<?php

namespace App\Console\Commands;

use App\Jobs\DeliverOutboxMessage;
use App\Models\OutboxMessage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('outbox:dispatch-pending')]
#[Description('Dispatch pending and stale outbox messages for delivery')]
class DispatchPendingOutboxMessages extends Command
{
    public function handle(): int
    {
        $dispatched = 0;

        OutboxMessage::query()
            ->where(function ($query): void {
                $query
                    ->where(function ($query): void {
                        $query
                            ->where('status', OutboxMessage::PENDING)
                            ->where('available_at', '<=', now());
                    })
                    ->orWhere(function ($query): void {
                        $query
                            ->where('status', OutboxMessage::SENDING)
                            ->where('locked_at', '<=', now()->subMinutes(5));
                    });
            })
            ->chunkById(100, function ($messages) use (&$dispatched): void {
                foreach ($messages as $message) {
                    try {
                        DeliverOutboxMessage::dispatch($message->id);
                    } catch (Throwable $exception) {
                        Log::warning('Unable to dispatch an outbox message.', [
                            'outbox_message_id' => $message->id,
                            'exception' => $exception,
                        ]);

                        continue;
                    }

                    $dispatched++;
                }
            });

        $this->info("Dispatched {$dispatched} outbox message(s).");

        return self::SUCCESS;
    }
}
