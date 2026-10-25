<?php

it('returns the deal fixture without authentication in success mode', function () {
    $this->getJson('/mock-api/deals/CRM-42')
        ->assertOk()
        ->assertJsonPath('data.id', 'CRM-42')
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.updated_at', '2026-10-21T00:00:00.000000Z')
        ->assertJsonPath('data.title', 'Mock deal CRM-42')
        ->assertJsonPath('data.status', 'NEW')
        ->assertJsonPath('data.amount', '10000.00');
});

it('returns the configured newer-version demo fixture', function () {
    $this->getJson('/mock-api/deals/CRM-DEMO-UPDATE')
        ->assertOk()
        ->assertJsonPath('data.id', 'CRM-DEMO-UPDATE')
        ->assertJsonPath('data.version', 19)
        ->assertJsonPath('data.updated_at', '2026-10-24T12:00:00.000000Z')
        ->assertJsonPath('data.title', 'Сделка обновлена из mock CRM')
        ->assertJsonPath('data.status', 'IN_PROGRESS')
        ->assertJsonPath('data.amount', '24500.00');
});

it('returns 429 twice and succeeds on the third rate-limited request', function () {
    $url = '/mock-api/deals/CRM-RATE-LIMITED?mode=rate_limited';

    $this->getJson($url)
        ->assertTooManyRequests()
        ->assertHeader('Retry-After', '1');

    $this->getJson($url)
        ->assertTooManyRequests();

    $this->getJson($url)
        ->assertOk()
        ->assertJsonPath('data.id', 'CRM-RATE-LIMITED');
});

it('returns 500 for every request in permanent failure mode', function () {
    config(['services.mock_crm.mode' => 'permanent_failure']);

    $this->getJson('/mock-api/deals/CRM-ERROR')
        ->assertServerError()
        ->assertJsonPath('message', 'Mock CRM server error.');

    $this->getJson('/mock-api/deals/CRM-ERROR')
        ->assertServerError();
});

it('rejects an unsupported mock API mode with a JSON validation response', function () {
    $this->getJson('/mock-api/deals/CRM-42?mode=random')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['mode']);
});
