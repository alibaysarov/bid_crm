<?php

use App\Enums\DealStatus;
use App\Jobs\WebhookDeal;
use App\Models\Deal;
use App\Models\Event;
use Illuminate\Support\Facades\Queue;

it('returns created for a new event and processing for a duplicate', function () {
    config(['services.webhook.token' => 'test-webhook-token']);
    Queue::fake();
    $data = [
        'event_id' => 'evt_123456',
        'event_time' => '2026-10-22T12:00:00Z',
        'deal' => [
            'id' => '123',
            'version' => 1,
            'updated_at' => '2026-10-22T11:59:30Z',
            'title' => 'Test deal',
            'status' => DealStatus::values()[0],
            'amount' => 1500.50,
        ],
    ];

    $this->withHeader('X-Webhook-Token', 'test-webhook-token')
        ->postJson('/api/webhooks/deals', $data)
        ->assertCreated()
        ->assertJsonPath('data.id', '123')
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.title', 'Test deal')
        ->assertJsonPath('meta.event_id', 'evt_123456')
        ->assertJsonPath('meta.processing_status', 'received');

    $this->withHeader('X-Webhook-Token', 'test-webhook-token')
        ->postJson('/api/webhooks/deals', $data)
        ->assertOk()
        ->assertJsonPath('data.id', '123')
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.title', 'Test deal')
        ->assertJsonPath('meta.event_id', 'evt_123456')
        ->assertJsonPath('meta.processing_status', 'received');

    $this->assertDatabaseCount('events', 1);
    $this->assertDatabaseHas('events', [
        'event_id' => 'evt_123456',
        'event_type' => 'webhook.deal.create',
        'status' => Event::CREATED,
    ]);
});

it('job is send to queue', function () {
    config(['services.webhook.token' => 'test-webhook-token']);
    Queue::fake();
    $data = [
        'event_id' => 'evt_123456',
        'event_time' => '2026-10-22T12:00:00Z',
        'deal' => [
            'id' => '123',
            'version' => 1,
            'updated_at' => '2026-10-22T11:59:30Z',
            'title' => 'Test deal',
            'status' => DealStatus::values()[0],
            'amount' => 1500.50,
        ],
    ];

    $this->withHeader('X-Webhook-Token', 'test-webhook-token')
        ->postJson('/api/webhooks/deals', $data)
        ->assertCreated()
        ->assertJsonPath('data.id', '123')
        ->assertJsonPath('meta.processing_status', 'received');

    Queue::assertPushed(WebhookDeal::class);

    $this->assertDatabaseHas('events', [
        'event_id' => 'evt_123456',
        'event_type' => 'webhook.deal.create',
        'status' => Event::CREATED,
    ]);

});

it('test event creates correctly', function () {

    $event = Event::query()->create([
        'event_id' => 'evt_123456',
        'event_type' => 'webhook.deal.create',
        'status' => Event::CREATED,
    ]);

    (new WebhookDeal([
        'id' => '123',
        'version' => 1,
        'updated_at' => '2026-10-22T11:59:30Z',
        'title' => 'Test deal',
        'amount' => 1500.50,
        'status' => 'NEW',
    ], $event->id))->handle();

    $this->assertDatabaseHas('deals', [
        'external_id' => '123',
        'external_version' => 1,
        'title' => 'Test deal',
    ]);

    $this->assertDatabaseHas('events', [
        'id' => $event->id,
        'status' => Event::SUCCESS,
    ]);

});

it('marks a webhook event as skipped when its external version is stale', function () {
    $deal = Deal::factory()->create([
        'external_id' => '123',
        'external_version' => 2,
        'title' => 'Latest deal',
    ]);
    $event = Event::query()->create([
        'event_id' => 'evt_stale_123',
        'event_type' => 'webhook.deal.create',
        'status' => Event::CREATED,
        'data' => [
            'id' => '123',
            'version' => 1,
            'updated_at' => '2026-10-22T11:59:30Z',
            'title' => 'Stale deal',
            'amount' => 1500.50,
            'status' => 'NEW',
        ],
    ]);

    (new WebhookDeal([
        'id' => '123',
        'version' => 1,
        'updated_at' => '2026-10-22T11:59:30Z',
        'title' => 'Stale deal',
        'amount' => 1500.50,
        'status' => 'NEW',
    ], $event->id))->handle();

    $this->assertDatabaseHas('deals', [
        'id' => $deal->id,
        'external_version' => 2,
        'title' => 'Latest deal',
    ]);
    $this->assertDatabaseHas('events', [
        'id' => $event->id,
        'status' => Event::SKIPPED,
    ]);
});
