<?php

use App\Events\DealRefreshStatusUpdated;
use App\Jobs\RefreshDeal;
use App\Models\Deal;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('applies the newer CRM version when a queued refresh runs after a manual edit', function () {
    config(['services.mock_crm.url' => 'https://mock-crm.test']);

    Queue::fake([RefreshDeal::class]);

    Http::preventStrayRequests();
    $externalId = 'crm_id_123';
    $baseUrl = config('services.mock_crm.url');
    $mockUrl = sprintf('%s/mock-api/deals/%s', $baseUrl, $externalId);
    Http::fake([
        $mockUrl => function () use ($externalId) {
            return Http::response([
                'data' => [
                    'id' => $externalId,
                    'version' => 2,
                    'updated_at' => '2026-10-21T00:00:00Z',
                    'title' => 'Версия из внешней CRM',
                    'status' => 'WON',
                    'amount' => '12500.00',
                ],
            ]);
        },
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $deal = Deal::factory()->create([
        'title' => 'Сделка1',
        'created_by_user_id' => $user->id,
        'status' => 'NEW',
        'external_version' => 1,
        'external_id' => $externalId,
    ]);

    $url = sprintf('/api/deals/%s/refresh', $deal->id);

    $this->postJson($url)->assertAccepted();

    $job = Queue::pushed(RefreshDeal::class)->first();

    $this->putJson('/api/deals/'.$deal->id, [
        'title' => 'Сделку обновил пользователь',
    ])->assertOk();

    $this->app->call([$job, 'handle']);

    Queue::assertPushed(RefreshDeal::class);

    $this->assertDatabaseHas('deals', [
        'id' => $deal->id,
        'title' => 'Версия из внешней CRM',
        'status' => 'WON',
        'amount' => '12500.00',
        'external_version' => 2,
    ]);
});

it('updates the deal and broadcasts completion for a newer CRM version', function () {
    $user = User::factory()->create();
    $deal = Deal::factory()->create([
        'external_id' => 'CRM-42',
        'external_version' => 1,
        'title' => 'Old deal title',
        'status' => 'NEW',
        'amount' => '10000.00',
    ]);

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
    Event::fake([DealRefreshStatusUpdated::class]);

    $this->app->call([new RefreshDeal($deal->id, $user->id), 'handle']);

    $this->assertDatabaseHas('deals', [
        'id' => $deal->id,
        'title' => 'Updated deal title',
        'status' => 'WON',
        'amount' => '12500.00',
        'external_version' => 2,
    ]);
    Event::assertDispatched(
        DealRefreshStatusUpdated::class,
        fn (DealRefreshStatusUpdated $event): bool => $event->userId === $user->id
            && $event->dealId === $deal->id
            && $event->success
            && $event->status === 'succeeded'
            && $event->broadcastWith()['deal']['external_refresh_status'] === 'succeeded'
            && $event->broadcastOn()[0]->name === 'private-App.Models.User.'.$user->id,
    );
});

it('does not overwrite a deal when CRM returns an older version', function () {
    $user = User::factory()->create();
    $deal = Deal::factory()->create([
        'external_id' => 'CRM-42',
        'external_version' => 3,
        'external_updated_at' => '2026-10-21T00:00:00Z',
        'title' => 'Latest CRM title',
        'status' => 'WON',
        'amount' => '50000.00',
    ]);

    config(['services.mock_crm.url' => 'https://mock-crm.test']);
    Http::preventStrayRequests();
    Http::fake([
        'https://mock-crm.test/mock-api/deals/CRM-42' => Http::response([
            'data' => [
                'id' => 'CRM-42',
                'version' => 2,
                'updated_at' => '2026-10-20T00:00:00Z',
                'title' => 'Stale CRM title',
                'status' => 'NEW',
                'amount' => '1000.00',
            ],
        ]),
    ]);
    Event::fake([DealRefreshStatusUpdated::class]);

    $this->app->call([new RefreshDeal($deal->id, $user->id), 'handle']);

    $this->assertDatabaseHas('deals', [
        'id' => $deal->id,
        'external_id' => 'CRM-42',
        'title' => 'Latest CRM title',
        'status' => 'WON',
        'amount' => '50000.00',
        'external_version' => 3,
    ]);
    Event::assertDispatched(
        DealRefreshStatusUpdated::class,
        fn (DealRefreshStatusUpdated $event): bool => $event->success
            && $event->status === 'succeeded'
            && $event->message === 'В сделке уже актуальная версия CRM.',
    );
});

it('broadcasts a failure when the refresh job exhausts its attempts', function () {
    $user = User::factory()->create();
    $deal = Deal::factory()->create(['external_id' => 'CRM-42']);
    Event::fake([DealRefreshStatusUpdated::class]);

    (new RefreshDeal($deal->id, $user->id))->failed(new RuntimeException('CRM unavailable'));

    Event::assertDispatched(
        DealRefreshStatusUpdated::class,
        fn (DealRefreshStatusUpdated $event): bool => $event->userId === $user->id
            && $event->dealId === $deal->id
            && ! $event->success
            && $event->status === 'failed',
    );
});
