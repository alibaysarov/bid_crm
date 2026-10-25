<?php

namespace App\Jobs;

use App\Models\Event;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class WebhookDeal implements ShouldQueue
{
    use Queueable;

    /**
     * @var array{id: string, version: int, updated_at: string, title: string, status: string, amount: int|float|string}
     */
    private array $attributes;

    private int $eventId;

    /**
     * @param  array{id: string, version: int, updated_at: string, title: string, status: string, amount: int|float|string}  $data
     */
    public function __construct(array $data, int $eventId)
    {
        $this->attributes = $data;
        $this->eventId = $eventId;
    }

    public function handle(): void
    {
        Event::query()
            ->whereKey($this->eventId)
            ->whereNotIn('status', [Event::SUCCESS, Event::SKIPPED])
            ->update([
                'status' => Event::PROCESSING,
                'processing_started_at' => now(),
                'error' => null,
            ]);

        DB::transaction(function (): void {
            $event = Event::query()->lockForUpdate()->findOrFail($this->eventId);

            if (in_array($event->status, [Event::SUCCESS, Event::SKIPPED], true)) {
                return;
            }

            $timestamp = now();
            $affectedRows = DB::affectingStatement('
                INSERT INTO deals (
                    external_id,
                    title,
                    amount,
                    created_at,
                    updated_at,
                    external_version,
                    external_updated_at,
                    status
                )
                VALUES (
                    :external_id,
                    :title,
                    :amount,
                    :created_at,
                    :updated_at,
                    :external_version,
                    :external_updated_at,
                    :status
                )
                ON CONFLICT (external_id)
                DO UPDATE SET
                    title            = EXCLUDED.title,
                    amount           = EXCLUDED.amount,
                    status           = EXCLUDED.status,
                    external_version = EXCLUDED.external_version,
                    external_updated_at = EXCLUDED.external_updated_at,
                    updated_at = EXCLUDED.updated_at
                WHERE deals.external_version IS NULL
                    OR deals.external_version < EXCLUDED.external_version
            ', [
                'external_id' => $this->attributes['id'],
                'title' => $this->attributes['title'],
                'amount' => $this->attributes['amount'],
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
                'status' => $this->attributes['status'],
                'external_version' => $this->attributes['version'],
                'external_updated_at' => $this->attributes['updated_at'] ?? $timestamp,
            ]);

            $event = Event::query()->lockForUpdate()->findOrFail($this->eventId);
            $event->status = $affectedRows > 0 ? Event::SUCCESS : Event::SKIPPED;
            $event->processing_started_at = null;
            $event->save();
        }, 1);
    }

    public function failed(Throwable $exception): void
    {
        Event::query()
            ->whereKey($this->eventId)
            ->whereNotIn('status', [Event::SUCCESS, Event::SKIPPED])
            ->update([
                'status' => Event::FAILED,
                'processing_started_at' => null,
                'error' => mb_substr($exception->getMessage(), 0, 1000),
            ]);
    }
}
