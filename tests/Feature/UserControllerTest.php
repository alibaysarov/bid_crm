<?php

use App\Models\User;

describe('user profile endpoints', function () {
    it("lists only the authenticated user's own profile", function () {
        $user = User::factory()->create();
        User::factory()->create();
        $this->actingAs($user);

        $this->getJson('/api/users')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $user->id);
    });

    it("updates the authenticated user's profile", function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->patchJson("/api/users/{$user->id}", [
            'first_name' => 'Updated',
            'email' => 'updated@example.test',
        ])
            ->assertOk()
            ->assertJsonPath('data.first_name', 'Updated')
            ->assertJsonPath('data.email', 'updated@example.test');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'first_name' => 'Updated',
            'email' => 'updated@example.test',
        ]);
    });

    it("does not allow a user to view another user's profile", function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $this->actingAs($user);

        $this->getJson("/api/users/{$otherUser->id}")->assertForbidden();
    });

    it("does not allow a user to update another user's profile", function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $this->actingAs($user);

        $this->patchJson("/api/users/{$otherUser->id}", ['first_name' => 'Attacker'])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', [
            'id' => $otherUser->id,
            'first_name' => 'Attacker',
        ]);
    });

    it("does not allow a user to delete another user's profile", function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $this->actingAs($user);

        $this->deleteJson("/api/users/{$otherUser->id}")->assertForbidden();

        $this->assertModelExists($otherUser);
    });

    it("deletes the authenticated user's own profile and invalidates the session", function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->deleteJson("/api/users/{$user->id}")->assertNoContent();

        $this->assertGuest();
        $this->assertModelMissing($user);
    });

    it('rejects profile endpoints without a session', function () {
        $this->getJson('/api/users')->assertUnauthorized();
    });
});
