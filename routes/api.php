<?php

use App\Http\Controllers\Api\DealController;
use App\Http\Controllers\Api\DealWebhookController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\VerifyWebhookToken;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/deals', DealWebhookController::class)
    ->middleware(VerifyWebhookToken::class)
    ->name('webhooks.deals');

Route::middleware('web')->group(function (): void {
    Route::prefix('auth')->name('auth.')->group(function (): void {
        Route::get('/csrf-cookie', fn () => response()->noContent())->name('csrf-cookie');
        Route::post('/register', [AuthController::class, 'register'])
            ->middleware('throttle:5,1')
            ->name('register');
        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:auth')
            ->name('login');
        Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])
            ->middleware('throttle:auth')
            ->name('password.email');
        Route::post('/reset-password', [AuthController::class, 'resetPassword'])
            ->middleware('throttle:auth')
            ->name('password.update');

        Route::middleware('auth')->group(function (): void {
            Route::get('/user', [AuthController::class, 'user'])->name('user');
            Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        });
    });

    Route::middleware('auth')->group(function (): void {
        Route::get('/events', [EventController::class, 'index'])->name('events.index');
        Route::post('/deals/{deal}/refresh', [DealController::class, 'refreshInfo'])
            ->name('deals.refresh');
        Route::apiResource('deals', DealController::class);
        Route::apiResource('users', UserController::class)
            ->only(['index', 'show', 'update', 'destroy']);
    });
});
