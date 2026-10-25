<?php

use App\Events\DealRefreshStatusUpdated;
use App\Jobs\DeliverOutboxMessage;
use App\Models\OutboxMessage;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

it('marks an outbox message sent after publishing its event', function () {
    $message = OutboxMessage::query()->create([
        'event_key' => 'refresh-123:succeeded',
        'event_type' => 'deal.refresh.updated',
        'payload' => [
            'user_id' => 7,
            'deal_id' => 12,
            'success' => true,
            'message' => 'Deal refreshed.',
            'status' => 'succeeded',
            'deal' => ['title' => 'Updated deal'],
        ],
        'status' => OutboxMessage::PENDING,
        'available_at' => now(),
    ]);
    Event::fake([DealRefreshStatusUpdated::class]);

    $this->app->call([new DeliverOutboxMessage($message->id), 'handle']);

    $this->assertDatabaseHas('outbox_messages', [
        'id' => $message->id,
        'status' => OutboxMessage::SENT,
        'delivery_attempts' => 1,
        'last_error' => null,
    ]);
    Event::assertDispatched(
        DealRefreshStatusUpdated::class,
        fn (DealRefreshStatusUpdated $event): bool => $event->dealId === 12
            && $event->status === 'succeeded',
    );
});

it('keeps a failed publication pending with a backoff time', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-24T12:00:00Z'));
    $message = OutboxMessage::query()->create([
        'event_key' => 'refresh-456:processing',
        'event_type' => 'deal.refresh.updated',
        'payload' => [
            'user_id' => 7,
            'deal_id' => 12,
            'success' => true,
            'message' => 'Refreshing deal.',
            'status' => 'processing',
            'deal' => ['title' => 'Deal'],
        ],
        'status' => OutboxMessage::PENDING,
        'available_at' => now(),
    ]);
    $this->mock(Dispatcher::class)
        ->shouldReceive('dispatch')
        ->once()
        ->andThrow(new RuntimeException('Reverb unavailable.'));

    $this->app->call([new DeliverOutboxMessage($message->id), 'handle']);

    $this->assertDatabaseHas('outbox_messages', [
        'id' => $message->id,
        'status' => OutboxMessage::PENDING,
        'delivery_attempts' => 1,
        'available_at' => '2026-10-24 12:00:02',
        'locked_at' => null,
        'last_error' => 'Reverb unavailable.',
    ]);
});

it('dispatches only due or stale outbox messages for recovery', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-24T12:00:00Z'));
    $dueMessage = OutboxMessage::query()->create([
        'event_key' => 'refresh-due:succeeded',
        'event_type' => 'deal.refresh.updated',
        'payload' => [],
        'status' => OutboxMessage::PENDING,
        'available_at' => now(),
    ]);
    OutboxMessage::query()->create([
        'event_key' => 'refresh-future:succeeded',
        'event_type' => 'deal.refresh.updated',
        'payload' => [],
        'status' => OutboxMessage::PENDING,
        'available_at' => now()->addMinute(),
    ]);
    Queue::fake([DeliverOutboxMessage::class]);

    $this->artisan('outbox:dispatch-pending')
        ->assertSuccessful()
        ->expectsOutput('Dispatched 1 outbox message(s).');

    Queue::assertPushed(
        DeliverOutboxMessage::class,
        fn (DeliverOutboxMessage $job): bool => $job->outboxMessageId === $dueMessage->id,
    );
});
