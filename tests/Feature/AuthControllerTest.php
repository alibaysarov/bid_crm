<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

describe('session authentication endpoints', function () {
    it('initializes a browser session with a CSRF cookie', function () {
        $this->getJson('/api/auth/csrf-cookie')
            ->assertNoContent()
            ->assertCookie('XSRF-TOKEN');
    });

    it('registers a user and authenticates the session', function () {
        $response = $this->postJson('/api/auth/register', [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.test',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.first_name', 'Ada')
            ->assertJsonPath('data.email', 'ada@example.test')
            ->assertJsonMissingPath('data.password');

        $this->assertAuthenticatedAs(User::query()->where('email', 'ada@example.test')->first());
    });

    it('rejects duplicate email registration', function () {
        User::factory()->create(['email' => 'ada@example.test']);

        $this->postJson('/api/auth/register', [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.test',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    });

    it('starts a session after valid login credentials', function () {
        $user = User::factory()->create([
            'email' => 'ada@example.test',
            'password' => Hash::make('CorrectPass123!'),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'ada@example.test',
            'password' => 'CorrectPass123!',
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        $this->assertAuthenticatedAs($user);
    });

    it('rejects invalid login credentials', function () {
        User::factory()->create([
            'email' => 'ada@example.test',
            'password' => Hash::make('CorrectPass123!'),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'ada@example.test',
            'password' => 'IncorrectPass123!',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertGuest();
    });

    it('ends the session on logout', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->postJson('/api/auth/logout')->assertNoContent();

        $this->assertGuest();
    });

    it("returns the current user's profile only to an authenticated session", function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->getJson('/api/auth/user')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonMissingPath('data.password');
    });

    it('rejects the current-user endpoint without a session', function () {
        $this->getJson('/api/auth/user')->assertUnauthorized();
    });
});

describe('password recovery endpoints', function () {
    it('sends a password reset notification for an existing account', function () {
        Notification::fake();
        $user = User::factory()->create();

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])
            ->assertAccepted()
            ->assertJsonPath('message', 'If the email address is registered, a password reset link will be sent.');

        Notification::assertSentTo($user, ResetPassword::class);
    });

    it('uses the same forgot-password response when the account does not exist', function () {
        Notification::fake();

        $this->postJson('/api/auth/forgot-password', ['email' => 'missing@example.test'])
            ->assertAccepted()
            ->assertJsonPath('message', 'If the email address is registered, a password reset link will be sent.');

        Notification::assertNothingSent();
    });

    it('changes the password with a valid reset token', function () {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'NewStrongPass123!',
            'password_confirmation' => 'NewStrongPass123!',
        ])->assertOk();

        expect(Hash::check('NewStrongPass123!', $user->fresh()->password))->toBeTrue();
    });

    it('rejects an invalid password reset token', function () {
        $user = User::factory()->create();

        $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'token' => 'invalid-token',
            'password' => 'NewStrongPass123!',
            'password_confirmation' => 'NewStrongPass123!',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    });
});
