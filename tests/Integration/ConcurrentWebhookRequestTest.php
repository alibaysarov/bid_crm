<?php

use App\Enums\DealStatus;
use Illuminate\Support\Facades\DB;
use Spatie\Fork\Fork;

it('returns one created response and processes one deal for concurrent duplicate requests', function () {
    $data = [
        'event_id' => 'evt_concurrent_123456',
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

    DB::purge();
    config(['services.webhook.token' => 'test-webhook-token']);

    $request = function () use ($data) {
        return test()
            ->withHeader('X-Webhook-Token', 'test-webhook-token')
            ->postJson('/api/webhooks/deals', $data)
            ->getStatusCode();
    };

    $results = Fork::new()->run($request, $request, $request, $request);

    sort($results);
    expect($results)->toBe([200, 200, 200, 201]);
    $this->assertDatabaseCount('events', 1);
    $this->assertDatabaseCount('deals', 1);
});
