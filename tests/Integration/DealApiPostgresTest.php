<?php

use App\Models\Deal;
use App\Models\User;

it('serves authenticated deal requests against a disposable PostgreSQL container', function () {
    $user = User::factory()->create();
    $deal = Deal::factory()->create([
        'title' => 'PostgreSQL integration deal',
        'created_by_user_id' => $user->id,
    ]);
    $this->actingAs($user);

    $this->getJson('/api/deals')
        ->assertOk()
        ->assertJsonPath('data.0.id', $deal->id)
        ->assertJsonPath('data.0.title', 'PostgreSQL integration deal');
});
