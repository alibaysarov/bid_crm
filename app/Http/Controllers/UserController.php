<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * List only the authenticated user's own profile.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', User::class);
        /** @var User $user */
        $user = $request->user('web');

        return response()->json([
            'data' => [$user->toPublicArray()],
        ]);
    }

    /**
     * Show a profile when it belongs to the authenticated user.
     */
    public function show(User $user): JsonResponse
    {
        Gate::authorize('view', $user);

        return response()->json(['data' => $user->toPublicArray()]);
    }

    /**
     * Update the authenticated user's own name and email.
     */
    public function update(Request $request, User $user): JsonResponse
    {
        Gate::authorize('update', $user);

        $validated = $request->validate([
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
        ]);

        $user->update($validated);

        return response()->json(['data' => $user->refresh()->toPublicArray()]);
    }

    /**
     * Delete the authenticated user's own account and invalidate its session.
     */
    public function destroy(Request $request, User $user): Response
    {
        Gate::authorize('delete', $user);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $user->delete();

        return response()->noContent();
    }
}
