<?php

use App\Models\Event;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('requires authentication to view the event journal', function () {
    $this->getJson('/api/events')->assertUnauthorized();
    $this->get('/events')->assertRedirect(route('login'));
});

it('filters and paginates events by type, status, and searchable deal data', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Event::query()->create([
        'event_id' => 'evt_matching_old',
        'event_type' => 'webhook.deal.create',
        'event_time' => '2026-10-20T10:00:00Z',
        'data' => ['id' => 'CRM-2', 'title' => 'Renewal', 'version' => 4],
        'status' => Event::FAILED,
        'error' => 'External CRM unavailable.',
    ]);
    Event::query()->create([
        'event_id' => 'evt_matching_new',
        'event_type' => 'webhook.deal.create',
        'event_time' => '2026-10-21T10:00:00Z',
        'data' => ['id' => 'CRM-2', 'title' => 'Renewal', 'version' => 5],
        'status' => Event::FAILED,
    ]);
    Event::query()->create([
        'event_id' => 'evt_other_type',
        'event_type' => 'webhook.user.update',
        'event_time' => '2026-10-22T10:00:00Z',
        'data' => ['id' => 'CRM-2', 'title' => 'Renewal', 'version' => 6],
        'status' => Event::FAILED,
    ]);
    Event::query()->create([
        'event_id' => 'evt_other_status',
        'event_type' => 'webhook.deal.create',
        'event_time' => '2026-10-23T10:00:00Z',
        'data' => ['id' => 'CRM-2', 'title' => 'Renewal', 'version' => 7],
        'status' => Event::SUCCESS,
    ]);

    $this->getJson('/api/events?event_type=webhook.deal.create&status=FAILED&search=CRM-2&page=2&per_page=1')
        ->assertOk()
        ->assertJsonPath('data.0.event_id', 'evt_matching_old')
        ->assertJsonPath('data.0.deal.id', 'CRM-2')
        ->assertJsonPath('data.0.deal.version', 4)
        ->assertJsonPath('data.0.error', 'External CRM unavailable.')
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.per_page', 1)
        ->assertJsonPath('meta.total', 2);

    $this->getJson('/api/events?search=Renewal')
        ->assertOk()
        ->assertJsonPath('meta.total', 4);
});

it('rejects invalid event filters', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->getJson('/api/events?status=UNKNOWN&page=0&per_page=101')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status', 'page', 'per_page']);
});

it('accepts skipped as an event status filter', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->getJson('/api/events?status=SKIPPED')
        ->assertOk()
        ->assertJsonPath('meta.total', 0);
});

it('renders the authenticated event journal page with available event types', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Event::query()->create([
        'event_id' => 'evt_page',
        'event_type' => 'webhook.deal.create',
        'event_time' => now(),
        'status' => Event::CREATED,
    ]);

    $this->get('/events')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('events/index')
            ->where('auth.user.id', $user->id)
            ->where('eventTypes.0', 'webhook.deal.create'));
});
