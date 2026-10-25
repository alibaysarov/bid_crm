<?php

use App\Jobs\RefreshDeal;
use App\Models\Deal;
use App\Models\OutboxMessage;
use App\Models\User;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

beforeEach(function (): void {
    Redis::connection('default')->flushdb();
});

it('retries rate-limited CRM refreshes through Redis and succeeds on the third attempt', function () {
    $user = User::factory()->create();
    $deal = Deal::factory()->create([
        'external_id' => 'CRM-REDIS-RATE-LIMITED',
        'external_version' => 1,
        'external_refresh_status' => 'queued',
    ]);

    config(['services.mock_crm.url' => 'https://mock-crm.test']);
    Http::preventStrayRequests();
    Http::fakeSequence('https://mock-crm.test/mock-api/deals/CRM-REDIS-RATE-LIMITED')
        ->push(['message' => 'Rate limit exceeded.'], 429)
        ->push(['message' => 'Rate limit exceeded.'], 429)
        ->push([
            'data' => [
                'id' => 'CRM-REDIS-RATE-LIMITED',
                'version' => 2,
                'updated_at' => '2026-10-21T00:00:00Z',
                'title' => 'Recovered deal',
                'status' => 'WON',
                'amount' => '12500.00',
            ],
        ]);

    $job = new RefreshDeal($deal->id, $user->id);
    $job->backoff = [0, 0];
    Queue::push($job);

    $worker = app('queue.worker');
    $options = new WorkerOptions(backoff: 0, sleep: 0, maxTries: 3, force: true);

    $worker->runNextJob('redis', 'default', $options);
    expect(Queue::connection('redis')->size('default'))->toBe(1);

    $worker->runNextJob('redis', 'default', $options);
    expect(Queue::connection('redis')->size('default'))->toBe(1);

    $worker->runNextJob('redis', 'default', $options);
    $worker->runNextJob('redis', 'default', $options);

    Http::assertSentCount(3);
    $this->assertDatabaseHas('deals', [
        'id' => $deal->id,
        'title' => 'Recovered deal',
        'status' => 'WON',
        'amount' => '12500.00',
        'external_version' => 2,
        'external_refresh_status' => 'succeeded',
    ]);
    $this->assertDatabaseHas('outbox_messages', [
        'event_type' => 'deal.refresh.updated',
        'status' => OutboxMessage::SENT,
    ]);
    $this->assertDatabaseCount('failed_jobs', 0);
    expect(Queue::connection('redis')->size('default'))->toBe(0);
});

it('marks a CRM refresh failed after three Redis worker attempts return server errors', function () {
    $user = User::factory()->create();
    $deal = Deal::factory()->create([
        'external_id' => 'CRM-REDIS-PERMANENT-FAILURE',
        'external_refresh_status' => 'queued',
    ]);

    config(['services.mock_crm.url' => 'https://mock-crm.test']);
    Http::preventStrayRequests();
    Http::fake([
        'https://mock-crm.test/mock-api/deals/CRM-REDIS-PERMANENT-FAILURE' => Http::response(
            ['message' => 'Server error.'],
            500,
        ),
    ]);

    $job = new RefreshDeal($deal->id, $user->id);
    $job->backoff = [0, 0];
    Queue::push($job);

    $worker = app('queue.worker');
    $options = new WorkerOptions(backoff: 0, sleep: 0, maxTries: 3, force: true);

    $worker->runNextJob('redis', 'default', $options);
    expect(Queue::connection('redis')->size('default'))->toBe(1);
    $worker->runNextJob('redis', 'default', $options);
    expect(Queue::connection('redis')->size('default'))->toBe(1);
    $worker->runNextJob('redis', 'default', $options);

    $worker->runNextJob('redis', 'default', $options);

    Http::assertSentCount(3);
    $this->assertDatabaseHas('deals', [
        'id' => $deal->id,
        'external_refresh_status' => 'failed',
        'external_refresh_error' => 'Не удалось обновить сделку после нескольких попыток.',
    ]);
    $this->assertDatabaseHas('outbox_messages', [
        'event_type' => 'deal.refresh.updated',
        'status' => OutboxMessage::SENT,
    ]);
    expect(Queue::connection('redis')->size('default'))->toBe(0);
});
