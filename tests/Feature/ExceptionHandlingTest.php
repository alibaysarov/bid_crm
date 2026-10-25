<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

describe('API exception handling', function () {
    it('returns only validation details and logs validation failures at debug level', function () {
        Log::spy();

        $response = $this->postJson('/api/auth/register', [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'invalid-email',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('status', 422)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonStructure(['errors' => ['email']])
            ->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('file')
            ->assertJsonMissingPath('trace');

        expect(array_keys($response->json()))->toBe(['status', 'message', 'errors']);

        Log::shouldHaveReceived('log')
            ->once()
            ->with('debug', 'Request exception', Mockery::on(
                fn (array $context): bool => $context['exception'] instanceof ValidationException,
            ));
    });

    it('returns a minimal authentication error and logs it at info level', function () {
        Log::spy();

        $response = $this->getJson('/api/deals');

        $response->assertUnauthorized()->assertExactJson([
            'status' => 401,
            'message' => 'Unauthenticated.',
        ]);

        Log::shouldHaveReceived('log')
            ->once()
            ->with('info', 'Request exception', Mockery::on(
                fn (array $context): bool => $context['exception'] instanceof AuthenticationException,
            ));
    });

    it('hides unexpected exception details and logs the exception at error level', function () {
        Log::spy();
        config(['app.debug' => true]);

        Route::get('/api/test-exception', function (): never {
            throw new RuntimeException('Sensitive internal details');
        });

        $response = $this->getJson('/api/test-exception');

        $response->assertInternalServerError()->assertExactJson([
            'status' => 500,
            'message' => 'An unexpected error occurred.',
        ]);

        Log::shouldHaveReceived('log')
            ->once()
            ->with('error', 'Request exception', Mockery::on(
                fn (array $context): bool => $context['exception'] instanceof RuntimeException,
            ));
    });
});
