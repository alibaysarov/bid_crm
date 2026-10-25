<?php

use App\Http\Controllers\MockApi\DealController as MockApiDealController;
use App\Models\Event;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/mock-api/deals/{id}', [MockApiDealController::class, 'show'])
    ->name('mock-api.deals.show');

Route::get('/', function (Request $request) {
    if ($request->user()) {
        return redirect()->route('crm.deals.index');
    }

    return redirect()->route('login');
})->name('home');

Route::get('/login', function (Request $request) {
    if ($request->user()) {
        return redirect()->route('crm.deals.index');
    }

    return Inertia::render('auth/login');
})->name('login');

Route::get('/deals', fn () => Inertia::render('deals/index', [
    'users' => User::query()
        ->orderBy('last_name')
        ->orderBy('first_name')
        ->get(['id', 'first_name', 'last_name'])
        ->map(fn (User $user): array => [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
        ])
        ->values(),
]))
    ->middleware('auth')
    ->name('crm.deals.index');

Route::get('/events', fn () => Inertia::render('events/index', [
    'eventTypes' => Event::query()
        ->whereNotNull('event_type')
        ->distinct()
        ->orderBy('event_type')
        ->pluck('event_type')
        ->values(),
]))
    ->middleware('auth')
    ->name('crm.events.index');
