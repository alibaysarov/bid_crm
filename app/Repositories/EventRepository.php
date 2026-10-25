<?php

namespace App\Repositories;

use App\Models\Event;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class EventRepository
{
    /**
     * Persist a webhook snapshot once per event type and external event ID.
     *
     * @param  array{id: string, version: int, updated_at: string, title: string, status: string, amount: int|float|string}  $deal
     * @return array{event: Event, created: bool}
     */
    public function receive(string $eventId, string $eventTime, array $deal): array
    {
        return DB::transaction(function () use ($eventId, $eventTime, $deal): array {
            $rows = DB::select(
                <<<'SQL'
                    INSERT INTO events (event_id, event_type, event_time, data, status)
                    VALUES (:eventId, :eventType, :eventTime, :data, :status)
                    ON CONFLICT (event_id) DO NOTHING
                    RETURNING id
                SQL,
                [
                    'eventId' => $eventId,
                    'eventType' => 'webhook.deal.create',
                    'eventTime' => $eventTime,
                    'data' => json_encode($deal, JSON_THROW_ON_ERROR),
                    'status' => Event::CREATED,
                ],
            );

            $event = count($rows) === 1
                ? Event::query()->whereKey($rows[0]->id)->firstOrFail()
                : Event::query()
                    ->where('event_id', $eventId)
                    ->where('event_type', 'webhook.deal.create')
                    ->firstOrFail();

            return [
                'event' => $event,
                'created' => count($rows) === 1,
            ];
        });
    }

    public function markFailed(int $eventId, string $error): void
    {
        Event::query()->whereKey($eventId)->update([
            'status' => Event::FAILED,
            'processing_started_at' => null,
            'error' => mb_substr($error, 0, 1000),
        ]);
    }

    /**
     * Filter and paginate the event log in a stable newest-first order.
     *
     * @param  array<string, mixed>  $options
     * @return LengthAwarePaginator<int, Event>
     */
    public function paginate(array $options): LengthAwarePaginator
    {
        $query = Event::query();

        if (isset($options['event_type'])) {
            $query->where('event_type', $options['event_type']);
        }

        if (isset($options['status'])) {
            $query->where('status', $options['status']);
        }

        if (isset($options['search']) && is_string($options['search'])) {
            $search = mb_strtolower(trim($options['search']));
            $escapedSearch = str_replace(
                ['!', '%', '_'],
                ['!!', '!%', '!_'],
                $search,
            );
            $pattern = '%'.$escapedSearch.'%';

            $query->where(function (Builder $query) use ($pattern): void {
                $query
                    ->whereRaw("LOWER(event_id) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhere('data->id', 'like', $pattern)
                    ->orWhere('data->title', 'like', $pattern);
            });
        }

        return $query
            ->orderByDesc('event_time')
            ->orderByDesc('id')
            ->paginate(
                perPage: (int) ($options['per_page'] ?? 15),
                page: (int) ($options['page'] ?? 1),
            )
            ->appends($options);
    }
}
