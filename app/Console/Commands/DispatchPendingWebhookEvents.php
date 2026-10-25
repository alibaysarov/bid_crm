<?php

namespace App\Console\Commands;

use App\Jobs\WebhookDeal;
use App\Models\Event;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('webhooks:dispatch-pending')]
#[Description('Dispatch stored webhook events that have not been applied or have failed')]
class DispatchPendingWebhookEvents extends Command
{
    private const int STALE_AFTER_MINUTES = 5;

    public function handle(): int
    {
        $dispatched = 0;

        Event::query()
            ->where(function ($query): void {
                $query
                    ->whereIn('status', [Event::CREATED, Event::FAILED])
                    ->orWhere(function ($query): void {
                        $query
                            ->where('status', Event::PROCESSING)
                            ->where(function ($query): void {
                                $query
                                    ->whereNull('processing_started_at')
                                    ->orWhere(
                                        'processing_started_at',
                                        '<=',
                                        now()->subMinutes(self::STALE_AFTER_MINUTES),
                                    );
                            });
                    });
            })
            ->whereNotNull('data')
            ->chunkById(100, function ($events) use (&$dispatched): void {
                foreach ($events as $event) {
                    if ($event->status === Event::PROCESSING) {
                        $claimed = Event::query()
                            ->whereKey($event->id)
                            ->where('status', Event::PROCESSING)
                            ->where(function ($query): void {
                                $query
                                    ->whereNull('processing_started_at')
                                    ->orWhere(
                                        'processing_started_at',
                                        '<=',
                                        now()->subMinutes(self::STALE_AFTER_MINUTES),
                                    );
                            })
                            ->update([
                                'processing_started_at' => now(),
                                'error' => null,
                            ]);

                        if ($claimed === 0) {
                            continue;
                        }
                    }

                    if ($event->status === Event::FAILED) {
                        $event->update([
                            'status' => Event::CREATED,
                            'processing_started_at' => null,
                            'error' => null,
                        ]);
                    }

                    try {
                        WebhookDeal::dispatch($event->data, $event->id);
                    } catch (Throwable $exception) {
                        $event->update([
                            'status' => Event::FAILED,
                            'processing_started_at' => null,
                            'error' => mb_substr($exception->getMessage(), 0, 1000),
                        ]);

                        throw $exception;
                    }

                    $dispatched++;
                }
            });

        $this->info("Dispatched {$dispatched} webhook event(s).");

        return self::SUCCESS;
    }
}
