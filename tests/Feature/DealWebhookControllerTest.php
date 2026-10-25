<?php

use App\Enums\DealStatus;
use App\Jobs\WebhookDeal;
use App\Models\Deal;
use App\Models\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['services.webhook.token' => 'test-webhook-token']);
});

it('creates a deal from a valid webhook event without a user session', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-24T12:00:00Z'));

    $this->withHeader('X-Webhook-Token', 'test-webhook-token')
        ->postJson('/api/webhooks/deals', [
            'event_id' => 'evt_123',
            'event_time' => '2026-10-15T15:30:00Z',
            'deal' => [
                'id' => '5678',
                'version' => 18,
                'updated_at' => '2026-10-15T15:29:50Z',
                'title' => 'Продажа курса',
                'status' => DealStatus::New->value,
                'amount' => 49000,
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.id', '5678')
        ->assertJsonPath('data.version', 18)
        ->assertJsonPath('data.updated_at', '2026-10-15T15:29:50.000000Z')
        ->assertJsonPath('data.title', 'Продажа курса')
        ->assertJsonPath('data.status', DealStatus::New->value)
        ->assertJsonPath('data.amount', '49000.00')
        ->assertJsonPath('meta.event_id', 'evt_123')
        ->assertJsonPath('meta.processing_status', 'applied');

    $this->assertDatabaseHas('deals', [
        'external_id' => '5678',
        'external_version' => 18,
        'external_updated_at' => '2026-10-15T15:29:50Z',
        'title' => 'Продажа курса',
        'status' => DealStatus::New->value,
        'amount' => '49000.00',
    ]);

    $createdDeal = Deal::query()->where('external_id', '5678')->firstOrFail();
    expect($createdDeal->created_at?->toISOString())
        ->toBe('2026-10-24T12:00:00.000000Z')
        ->and($createdDeal->updated_at?->toISOString())
        ->toBe('2026-10-24T12:00:00.000000Z');

    $this->withHeader('X-Webhook-Token', 'test-webhook-token')
        ->postJson('/api/webhooks/deals', [
            'event_id' => 'evt_123',
            'event_time' => '2026-10-15T15:30:00Z',
            'deal' => [
                'id' => '5678',
                'version' => 18,
                'updated_at' => '2026-10-15T15:29:50Z',
                'title' => 'Продажа курса',
                'status' => DealStatus::New->value,
                'amount' => 49000,
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.id', '5678')
        ->assertJsonPath('data.version', 18)
        ->assertJsonPath('data.title', 'Продажа курса')
        ->assertJsonPath('meta.event_id', 'evt_123')
        ->assertJsonPath('meta.processing_status', 'applied');

    $this->assertDatabaseCount('events', 1);
});

it('updates the deal matching the external identifier', function () {
    $deal = Deal::factory()->create([
        'external_id' => '5678',
        'external_version' => 17,
        'title' => 'Previous title',
    ]);
    $createdAt = $deal->created_at?->toISOString();
    $this->travelTo(new DateTimeImmutable('2026-10-24T12:00:00Z'));

    $this->withHeader('X-Webhook-Token', 'test-webhook-token')
        ->postJson('/api/webhooks/deals', [
            'event_id' => 'evt_124',
            'event_time' => '2026-10-15T15:31:00Z',
            'deal' => [
                'id' => '5678',
                'version' => 18,
                'updated_at' => '2026-10-15T15:30:50Z',
                'title' => 'Продажа курса',
                'status' => DealStatus::Won->value,
                'amount' => 49000,
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.id', '5678')
        ->assertJsonPath('data.version', 18)
        ->assertJsonPath('data.title', 'Продажа курса')
        ->assertJsonPath('data.status', DealStatus::Won->value)
        ->assertJsonPath('meta.processing_status', 'applied');

    $this->assertDatabaseCount('deals', 1);
    $this->assertDatabaseHas('deals', [
        'id' => $deal->id,
        'external_id' => '5678',
        'external_version' => 18,
        'title' => 'Продажа курса',
        'status' => DealStatus::Won->value,
    ]);

    $updatedDeal = $deal->fresh();
    expect($updatedDeal->created_at?->toISOString())->toBe($createdAt)
        ->and($updatedDeal->updated_at?->toISOString())
        ->toBe('2026-10-24T12:00:00.000000Z');
});

it('does not overwrite a deal with a newer source version', function () {
    Deal::factory()->create([
        'external_id' => '5678',
        'external_version' => 18,
        'external_updated_at' => '2026-10-15T15:29:50Z',
        'title' => 'Newest title',
        'status' => DealStatus::Won->value,
        'amount' => 50000,
    ]);

    $this->withHeader('X-Webhook-Token', 'test-webhook-token')
        ->postJson('/api/webhooks/deals', [
            'event_id' => 'evt_stale_1',
            'event_time' => '2026-10-15T15:31:00Z',
            'deal' => [
                'id' => '5678',
                'version' => 17,
                'updated_at' => '2026-10-15T15:28:50Z',
                'title' => 'Stale title',
                'status' => DealStatus::New->value,
                'amount' => 1000,
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.version', 18)
        ->assertJsonPath('data.title', 'Newest title')
        ->assertJsonPath('data.status', DealStatus::Won->value)
        ->assertJsonPath('meta.processing_status', 'skipped');

    $this->assertDatabaseCount('deals', 1);
    $this->assertDatabaseHas('deals', [
        'external_id' => '5678',
        'external_version' => 18,
        'title' => 'Newest title',
        'status' => DealStatus::Won->value,
        'amount' => 50000,
    ]);
    $this->assertDatabaseHas('events', [
        'event_id' => 'evt_stale_1',
        'status' => Event::SKIPPED,
    ]);
});

it('re-dispatches persisted events that have not been applied', function () {
    Queue::fake();
    $event = Event::query()->create([
        'event_id' => 'evt_recovery_1',
        'event_type' => 'webhook.deal.create',
        'status' => Event::FAILED,
        'error' => 'Temporary queue failure.',
        'data' => [
            'id' => '5678',
            'version' => 18,
            'updated_at' => '2026-10-15T15:29:50Z',
            'title' => 'Продажа курса',
            'status' => DealStatus::New->value,
            'amount' => '49000',
        ],
    ]);

    $this->artisan('webhooks:dispatch-pending')->assertSuccessful();

    Queue::assertPushed(WebhookDeal::class);
    $this->assertDatabaseHas('events', [
        'id' => $event->id,
        'status' => Event::CREATED,
        'error' => null,
    ]);
});

it('re-dispatches only processing webhook events that have gone stale', function () {
    Queue::fake([WebhookDeal::class]);
    $this->travelTo(new DateTimeImmutable('2026-10-24T12:00:00Z'));

    $staleEvent = Event::query()->create([
        'event_id' => 'evt_stale_processing',
        'event_type' => 'webhook.deal.create',
        'status' => Event::PROCESSING,
        'processing_started_at' => now()->subMinutes(6),
        'data' => [
            'id' => '5678',
            'version' => 18,
            'updated_at' => '2026-10-15T15:29:50Z',
            'title' => 'Продажа курса',
            'status' => DealStatus::New->value,
            'amount' => '49000',
        ],
    ]);
    $freshEvent = Event::query()->create([
        'event_id' => 'evt_fresh_processing',
        'event_type' => 'webhook.deal.create',
        'status' => Event::PROCESSING,
        'processing_started_at' => now()->subMinutes(4),
        'data' => [
            'id' => '5679',
            'version' => 18,
            'updated_at' => '2026-10-15T15:29:50Z',
            'title' => 'Другой курс',
            'status' => DealStatus::New->value,
            'amount' => '49000',
        ],
    ]);
    $legacyEvent = Event::query()->create([
        'event_id' => 'evt_processing_without_timestamp',
        'event_type' => 'webhook.deal.create',
        'status' => Event::PROCESSING,
        'processing_started_at' => null,
        'data' => [
            'id' => '5680',
            'version' => 18,
            'updated_at' => '2026-10-15T15:29:50Z',
            'title' => 'Сделка без времени обработки',
            'status' => DealStatus::New->value,
            'amount' => '49000',
        ],
    ]);

    $this->artisan('webhooks:dispatch-pending')->assertSuccessful();
    $this->artisan('webhooks:dispatch-pending')->assertSuccessful();

    Queue::assertPushed(WebhookDeal::class, 2);
    $this->assertDatabaseHas('events', [
        'id' => $staleEvent->id,
        'status' => Event::PROCESSING,
        'processing_started_at' => '2026-10-24 12:00:00',
    ]);
    $this->assertDatabaseHas('events', [
        'id' => $freshEvent->id,
        'status' => Event::PROCESSING,
        'processing_started_at' => '2026-10-24 11:56:00',
    ]);
    $this->assertDatabaseHas('events', [
        'id' => $legacyEvent->id,
        'status' => Event::PROCESSING,
        'processing_started_at' => '2026-10-24 12:00:00',
    ]);
});

it('rejects an invalid webhook event without creating a deal', function () {
    $this->withHeader('X-Webhook-Token', 'test-webhook-token')
        ->postJson('/api/webhooks/deals', [
            'event_id' => 'evt_123',
            'event_time' => 'not-a-date',
            'deal' => [
                'id' => '5678',
                'version' => 0,
                'updated_at' => 'not-a-date',
                'title' => ['invalid'],
                'status' => 'UNKNOWN',
                'amount' => -1,
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'event_time',
            'deal.version',
            'deal.updated_at',
            'deal.title',
            'deal.status',
            'deal.amount',
        ]);

    $this->assertDatabaseCount('deals', 0);
});

it('requires every field in the webhook event', function () {
    $this->withHeader('X-Webhook-Token', 'test-webhook-token')
        ->postJson('/api/webhooks/deals', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'event_id',
            'event_time',
            'deal.id',
            'deal.version',
            'deal.updated_at',
            'deal.title',
            'deal.status',
            'deal.amount',
        ]);

    $this->assertDatabaseCount('deals', 0);
});

it('rejects webhook requests without a valid shared token', function () {
    $this->postJson('/api/webhooks/deals', [])
        ->assertUnauthorized()
        ->assertJsonPath('message', 'Invalid webhook token.');

    $this->withHeader('X-Webhook-Token', 'incorrect-token')
        ->postJson('/api/webhooks/deals', [])
        ->assertUnauthorized();

    $this->assertDatabaseCount('events', 0);
});

it('fails closed when the webhook token is not configured', function () {
    config(['services.webhook.token' => null]);

    $this->withHeader('X-Webhook-Token', 'test-webhook-token')
        ->postJson('/api/webhooks/deals', [])
        ->assertServiceUnavailable()
        ->assertJsonPath('message', 'Webhook authentication is not configured.');

    $this->assertDatabaseCount('events', 0);
});
