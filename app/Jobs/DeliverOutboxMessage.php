<?php

namespace App\Jobs;

use App\Events\DealRefreshStatusUpdated;
use App\Models\OutboxMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeliverOutboxMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public int $outboxMessageId) {}

    public function handle(Dispatcher $events): void
    {
        $payload = DB::transaction(function (): ?array {
            $message = OutboxMessage::query()
                ->whereKey($this->outboxMessageId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($message->status === OutboxMessage::SENT) {
                return null;
            }

            if (
                $message->status === OutboxMessage::SENDING
                && $message->locked_at?->greaterThan(now()->subMinutes(5))
            ) {
                return null;
            }

            if (
                $message->status === OutboxMessage::PENDING
                && $message->available_at?->isFuture()
            ) {
                return null;
            }

            $message->update([
                'status' => OutboxMessage::SENDING,
                'delivery_attempts' => $message->delivery_attempts + 1,
                'locked_at' => now(),
                'last_error' => null,
            ]);

            return $message->payload;
        });

        if ($payload === null) {
            return;
        }

        try {
            $events->dispatch(new DealRefreshStatusUpdated(
                (int) $payload['user_id'],
                (int) $payload['deal_id'],
                (bool) $payload['success'],
                (string) $payload['message'],
                (string) $payload['status'],
                $payload['deal'],
            ));
        } catch (Throwable $exception) {
            $this->scheduleRetry($exception);

            return;
        }

        OutboxMessage::query()->whereKey($this->outboxMessageId)->update([
            'status' => OutboxMessage::SENT,
            'sent_at' => now(),
            'locked_at' => null,
            'last_error' => null,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception === null) {
            return;
        }

        $this->scheduleRetry($exception);
    }

    private function scheduleRetry(Throwable $exception): void
    {
        $message = OutboxMessage::query()->find($this->outboxMessageId);

        if ($message === null || $message->status === OutboxMessage::SENT) {
            return;
        }

        $delay = min(300, 2 ** min($message->delivery_attempts, 8));
        $message->update([
            'status' => OutboxMessage::PENDING,
            'available_at' => now()->addSeconds($delay),
            'locked_at' => null,
            'last_error' => mb_substr($exception->getMessage(), 0, 1000),
        ]);

        Log::warning('Outbox message delivery failed and will be retried.', [
            'outbox_message_id' => $message->id,
            'delivery_attempts' => $message->delivery_attempts,
            'exception' => $exception,
        ]);
    }
}
