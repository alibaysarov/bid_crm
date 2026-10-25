<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('syncs the generated collection to the configured Postman collection', function () {
    config([
        'services.postman.api_key' => 'test-api-key',
        'services.postman.collection_uid' => 'collection-123',
    ]);
    Http::preventStrayRequests();
    Http::fake([
        'https://api.getpostman.com/collections/collection-123' => Http::response([
            'collection' => ['uid' => 'collection-123'],
        ]),
    ]);

    $this->artisan('postman:sync')
        ->expectsOutput('Postman collection synced successfully.')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->url() === 'https://api.getpostman.com/collections/collection-123'
        && $request->hasHeader('X-Api-Key', 'test-api-key')
        && isset($request->data()['collection']['info'], $request->data()['collection']['item']));
});

it('reports Postman API errors without treating the collection as synced', function () {
    config([
        'services.postman.api_key' => 'test-api-key',
        'services.postman.collection_uid' => 'collection-123',
    ]);
    Http::preventStrayRequests();
    Http::fake([
        'https://api.getpostman.com/collections/collection-123' => Http::response([], 403),
    ]);

    $this->artisan('postman:sync')
        ->expectsOutput('Postman API returned HTTP 403; the collection was not synced.')
        ->assertFailed();
});

it('requires Postman credentials before syncing', function () {
    config([
        'services.postman.api_key' => null,
        'services.postman.collection_uid' => null,
    ]);
    Http::preventStrayRequests();

    $this->artisan('postman:sync')
        ->expectsOutput('Set POSTMAN_API_KEY and POSTMAN_COLLECTION_UID in your local environment.')
        ->assertFailed();
});
