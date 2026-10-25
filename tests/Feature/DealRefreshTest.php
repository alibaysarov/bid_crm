<?php

use App\Events\DealRefreshStatusUpdated;
use App\Jobs\DeliverOutboxMessage;
use App\Jobs\RefreshDeal;
use App\Models\Deal;
use App\Models\OutboxMessage;
use App\Models\User;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('requires authentication to queue a deal refresh', function () {
    $deal = Deal::factory()->create(['external_id' => 'CRM-42']);

    $this->postJson("/api/deals/{$deal->id}/refresh")
        ->assertUnauthorized();
});

it('queues an external deal refresh for the authenticated user', function () {
    $user = authenticateApiUser($this);
    $deal = Deal::factory()->create(['external_id' => 'CRM-42']);
    Queue::fake([RefreshDeal::class]);

    $this->postJson("/api/deals/{$deal->id}/refresh")
        ->assertAccepted()
        ->assertJsonPath('message', 'Deal refresh was queued.')
        ->assertJsonPath('status', 'queued');

    $this->assertDatabaseHas('deals', [
        'id' => $deal->id,
        'external_refresh_status' => 'queued',
        'external_refresh_error' => null,
    ]);

    Queue::assertPushed(
        RefreshDeal::class,
        fn (RefreshDeal $job): bool => $job->dealId === $deal->id
            && $job->userId === $user->id,
    );
});

it('persists the completed refresh status for a later deal request', function () {
    $user = User::factory()->create();
    $deal = Deal::factory()->create([
        'external_id' => 'CRM-42',
        'external_version' => 1,
        'external_refresh_status' => 'queued',
    ]);
    $this->actingAs($user);
    config(['services.mock_crm.url' => 'https://mock-crm.test']);
    Http::preventStrayRequests();
    Http::fake([
        'https://mock-crm.test/mock-api/deals/CRM-42' => Http::response([
            'data' => [
                'id' => 'CRM-42',
                'version' => 2,
                'updated_at' => '2026-10-21T00:00:00Z',
                'title' => 'Updated deal title',
                'status' => 'WON',
                'amount' => '12500.00',
            ],
        ]),
    ]);

    $this->app->call([new RefreshDeal($deal->id, $user->id), 'handle']);

    $this->getJson("/api/deals/{$deal->id}")
        ->assertOk()
        ->assertJsonPath('data.external_refresh_status', 'succeeded')
        ->assertJsonPath('data.external_refresh_error', null);

    $this->assertDatabaseCount('outbox_messages', 1);
    $this->assertDatabaseHas('outbox_messages', [
        'event_type' => 'deal.refresh.updated',
        'status' => OutboxMessage::SENT,
    ]);
});

it('completes a refresh while its notifications await delivery', function () {
    $user = User::factory()->create();
    $deal = Deal::factory()->create([
        'external_id' => 'CRM-OUTBOX-FAILURE',
        'external_version' => 1,
        'external_refresh_status' => 'queued',
    ]);
    config(['services.mock_crm.url' => 'https://mock-crm.test']);
    Http::preventStrayRequests();
    Http::fake([
        'https://mock-crm.test/mock-api/deals/CRM-OUTBOX-FAILURE' => Http::response([
            'data' => [
                'id' => 'CRM-OUTBOX-FAILURE',
                'version' => 2,
                'updated_at' => '2026-10-21T00:00:00Z',
                'title' => 'Updated independently of Reverb',
                'status' => 'WON',
                'amount' => '12500.00',
            ],
        ]),
    ]);
    Queue::fake([DeliverOutboxMessage::class]);

    $this->app->call([new RefreshDeal($deal->id, $user->id), 'handle']);

    $this->assertDatabaseHas('deals', [
        'id' => $deal->id,
        'title' => 'Updated independently of Reverb',
        'external_version' => 2,
        'external_refresh_status' => 'succeeded',
    ]);
    $this->assertDatabaseHas('outbox_messages', [
        'event_type' => 'deal.refresh.updated',
        'status' => OutboxMessage::PENDING,
    ]);
    Queue::assertPushed(DeliverOutboxMessage::class, 1);
});

it('does not mark a committed refresh failed after a later queue failure', function () {
    $user = User::factory()->create();
    $deal = Deal::factory()->create([
        'external_id' => 'CRM-COMMITTED',
        'external_refresh_status' => 'succeeded',
        'external_version' => 2,
    ]);

    (new RefreshDeal($deal->id, $user->id))
        ->failed(new RuntimeException('Worker failed after commit.'));

    $this->assertDatabaseHas('deals', [
        'id' => $deal->id,
        'external_refresh_status' => 'succeeded',
        'external_version' => 2,
    ]);
    $this->assertDatabaseCount('outbox_messages', 0);
});

it('applies the configured demo fixture when its version is newer than the deal', function () {
    $user = User::factory()->create();
    $externalId = 'CRM-DEMO-UPDATE';
    $fixture = config("services.mock_crm.deals.{$externalId}");
    $deal = Deal::factory()->create([
        'external_id' => $externalId,
        'external_version' => 18,
        'title' => 'Локальное название',
        'status' => 'NEW',
        'amount' => '10000.00',
    ]);
    config(['services.mock_crm.url' => 'https://mock-crm.test']);
    Http::preventStrayRequests();
    Http::fake([
        "https://mock-crm.test/mock-api/deals/{$externalId}" => Http::response([
            'data' => [
                'id' => $externalId,
                ...$fixture,
            ],
        ]),
    ]);

    $this->app->call([new RefreshDeal($deal->id, $user->id), 'handle']);

    $this->assertDatabaseHas('deals', [
        'id' => $deal->id,
        'title' => 'Сделка обновлена из mock CRM',
        'status' => 'IN_PROGRESS',
        'amount' => '24500.00',
        'external_version' => 19,
        'external_refresh_status' => 'succeeded',
    ]);
});

it('retries two rate-limited refresh requests and succeeds on the third request', function () {
    $user = User::factory()->create();
    $deal = Deal::factory()->create([
        'external_id' => 'CRM-RATE-LIMITED',
        'external_version' => 1,
        'external_refresh_status' => 'queued',
    ]);
    config(['services.mock_crm.url' => 'https://mock-crm.test']);
    Http::preventStrayRequests();
    Http::fakeSequence('https://mock-crm.test/mock-api/deals/CRM-RATE-LIMITED')
        ->push(['message' => 'Rate limit exceeded.'], 429)
        ->push(['message' => 'Rate limit exceeded.'], 429)
        ->push([
            'data' => [
                'id' => 'CRM-RATE-LIMITED',
                'version' => 2,
                'updated_at' => '2026-10-21T00:00:00Z',
                'title' => 'Recovered deal',
                'status' => 'NEW',
                'amount' => '12500.00',
            ],
        ]);
    $job = new RefreshDeal($deal->id, $user->id);

    expect(fn () => $this->app->call([$job, 'handle']))
        ->toThrow(RequestException::class);
    expect(fn () => $this->app->call([$job, 'handle']))
        ->toThrow(RequestException::class);
    $this->app->call([$job, 'handle']);

    Http::assertSentCount(3);
    $this->assertDatabaseHas('deals', [
        'id' => $deal->id,
        'title' => 'Recovered deal',
        'external_version' => 2,
        'external_refresh_status' => 'succeeded',
        'external_refresh_error' => null,
    ]);
    expect($job->tries)->toBe(3);
    expect($job->backoff)->toBe([1, 5]);
});

it('marks a refresh failed after all three requests return server errors', function () {
    $user = User::factory()->create();
    $deal = Deal::factory()->create([
        'external_id' => 'CRM-PERMANENT-FAILURE',
        'external_refresh_status' => 'queued',
    ]);
    $this->actingAs($user);
    config(['services.mock_crm.url' => 'https://mock-crm.test']);
    Http::preventStrayRequests();
    Http::fakeSequence('https://mock-crm.test/mock-api/deals/CRM-PERMANENT-FAILURE')
        ->push(['message' => 'Server error.'], 500)
        ->push(['message' => 'Server error.'], 500)
        ->push(['message' => 'Server error.'], 500);
    Event::fake([DealRefreshStatusUpdated::class]);
    $job = new RefreshDeal($deal->id, $user->id);

    expect(fn () => $this->app->call([$job, 'handle']))
        ->toThrow(RequestException::class);
    expect(fn () => $this->app->call([$job, 'handle']))
        ->toThrow(RequestException::class);
    expect(fn () => $this->app->call([$job, 'handle']))
        ->toThrow(RequestException::class);
    $job->failed(new RuntimeException('External CRM unavailable.'));

    Http::assertSentCount(3);
    $this->getJson("/api/deals/{$deal->id}")
        ->assertOk()
        ->assertJsonPath('data.external_refresh_status', 'failed')
        ->assertJsonPath(
            'data.external_refresh_error',
            'Не удалось обновить сделку после нескольких попыток.',
        );
    Event::assertDispatched(
        DealRefreshStatusUpdated::class,
        fn (DealRefreshStatusUpdated $event): bool => ! $event->success
            && $event->dealId === $deal->id,
    );
    expect($job->tries)->toBe(3);
});

it('keeps local edits when the external response has the same version', function () {
    $user = User::factory()->create();
    $deal = Deal::factory()->create([
        'external_id' => 'CRM-EQUAL-VERSION',
        'external_version' => 4,
        'title' => 'Local title',
        'status' => 'IN_PROGRESS',
        'amount' => '3200.00',
        'external_refresh_status' => 'queued',
    ]);
    config(['services.mock_crm.url' => 'https://mock-crm.test']);
    Http::preventStrayRequests();
    Http::fake([
        'https://mock-crm.test/mock-api/deals/CRM-EQUAL-VERSION' => Http::response([
            'data' => [
                'id' => 'CRM-EQUAL-VERSION',
                'version' => 4,
                'updated_at' => '2026-10-21T00:00:00Z',
                'title' => 'Conflicting external title',
                'status' => 'WON',
                'amount' => '99000.00',
            ],
        ]),
    ]);

    $this->app->call([new RefreshDeal($deal->id, $user->id), 'handle']);

    $this->assertDatabaseHas('deals', [
        'id' => $deal->id,
        'title' => 'Local title',
        'status' => 'IN_PROGRESS',
        'amount' => '3200.00',
        'external_version' => 4,
        'external_refresh_status' => 'succeeded',
    ]);
});

it('rejects deals that are not linked to an external CRM', function () {
    authenticateApiUser($this);
    $deal = Deal::factory()->create(['external_id' => null]);
    Queue::fake([RefreshDeal::class]);

    $this->postJson("/api/deals/{$deal->id}/refresh")
        ->assertUnprocessable()
        ->assertJsonPath(
            'errors.deal.0',
            'Only deals linked to an external CRM can be refreshed.',
        );

    Queue::assertNotPushed(RefreshDeal::class);
});

it('allows a manually linked deal to be refreshed from the external CRM', function () {
    $user = authenticateApiUser($this);
    $deal = Deal::factory()->create(['external_id' => null]);
    Queue::fake([RefreshDeal::class]);

    $this->patchJson("/api/deals/{$deal->id}", [
        'external_id' => 'CRM-42',
    ])
        ->assertOk()
        ->assertJsonPath('data.external_id', 'CRM-42');

    $this->postJson("/api/deals/{$deal->id}/refresh")
        ->assertAccepted()
        ->assertJsonPath('message', 'Deal refresh was queued.');

    Queue::assertPushed(
        RefreshDeal::class,
        fn (RefreshDeal $job): bool => $job->dealId === $deal->id
            && $job->userId === $user->id,
    );
});
