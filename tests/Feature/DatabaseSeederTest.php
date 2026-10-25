<?php

use App\Models\Deal;
use App\Models\User;

it('seeds users before deals and assigns both deal user relationships', function () {
    $this->seed();

    $this->assertDatabaseCount('users', 10);
    $this->assertDatabaseCount('deals', 30);

    expect(Deal::query()->whereHas('createdBy')->count())->toBe(30);
    expect(Deal::query()->whereHas('assignedTo')->count())->toBe(30);
    expect(User::query()->where('email', 'test@example.com')->exists())->toBeTrue();
});
