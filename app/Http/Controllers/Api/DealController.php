<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexDealsRequest;
use App\Http\Requests\StoreDealRequest;
use App\Http\Requests\UpdateDealRequest;
use App\Http\Resources\DealResource;
use App\Jobs\RefreshDeal;
use App\Models\Deal;
use App\Models\User;
use App\Repositories\DealRepository;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Expose the deal CRUD API without performing database operations directly.
 */
class DealController extends Controller
{
    /**
     * Return a filtered, sorted, and paginated list of deals.
     */
    public function index(
        IndexDealsRequest $request,
        DealRepository $deals,
    ): AnonymousResourceCollection {
        Gate::authorize('viewAny', Deal::class);

        return DealResource::collection($deals->paginate($request->validated()));
    }

    /**
     * Create a deal using validated manual fields.
     */
    public function store(
        StoreDealRequest $request,
        DealRepository $deals,
    ): JsonResponse {
        Gate::authorize('create', Deal::class);

        $attributes = $request->validated();
        $attributes['created_by_user_id'] = $request->user()?->getAuthIdentifier();

        return DealResource::make($deals->create($attributes))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Return one deal by its identifier.
     */
    public function show(int $deal, DealRepository $deals): DealResource
    {
        $record = $deals->findOrFail($deal);
        Gate::authorize('view', $record);

        return DealResource::make($record);
    }

    /**
     * Update only fields accepted by the manual deal request.
     */
    public function update(
        UpdateDealRequest $request,
        int $deal,
        DealRepository $deals,
    ): DealResource {
        $record = $deals->findOrFail($deal);
        Gate::authorize('update', $record);

        return DealResource::make($deals->update($record->id, $request->validated()));
    }

    /**
     * Delete a deal by its identifier.
     */
    public function destroy(int $deal, DealRepository $deals): Response
    {
        $record = $deals->findOrFail($deal);
        Gate::authorize('delete', $record);
        $deals->delete($record->id);

        return response()->noContent();
    }

    /**
     * Queue a refresh from the external CRM.
     */
    public function refreshInfo(Request $request, int $deal, DealRepository $deals): JsonResponse
    {
        $record = $deals->findOrFail($deal);
        Gate::authorize('update', $record);
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        if ($record->external_id === null) {
            throw ValidationException::withMessages([
                'deal' => 'Only deals linked to an external CRM can be refreshed.',
            ]);
        }

        $record->update([
            'external_refresh_status' => 'queued',
            'external_refresh_error' => null,
            'external_refresh_updated_at' => now(),
        ]);

        try {
            RefreshDeal::dispatch($record->id, $user->id);
        } catch (Throwable $exception) {
            $record->update([
                'external_refresh_status' => 'failed',
                'external_refresh_error' => 'Не удалось поставить обновление в очередь.',
                'external_refresh_updated_at' => now(),
            ]);

            throw $exception;
        }

        return response()->json([
            'message' => 'Deal refresh was queued.',
            'status' => $record->external_refresh_status,
        ], Response::HTTP_ACCEPTED);
    }
}
