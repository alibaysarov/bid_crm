<?php

use App\Models\Event;
use Illuminate\Database\QueryException;

it('rejects the same event ID for different event types', function () {
    Event::query()->create([
        'event_id' => 'evt_42',
        'event_type' => 'created',
    ]);

    expect(fn () => Event::query()->create([
        'event_id' => 'evt_42',
        'event_type' => 'updated',
    ]))->toThrow(QueryException::class);

    $this->assertDatabaseCount('events', 1);
});

it('rejects duplicate event IDs with the same event type', function () {
    Event::query()->create([
        'event_id' => 'evt_42',
        'event_type' => 'created',
    ]);

    expect(fn () => Event::query()->create([
        'event_id' => 'evt_42',
        'event_type' => 'created',
    ]))->toThrow(QueryException::class);

    $this->assertDatabaseCount('events', 1);
});
