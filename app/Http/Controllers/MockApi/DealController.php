<?php

namespace App\Http\Controllers\MockApi;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DealController extends Controller
{
    private const string MODE_SUCCESS = 'success';

    private const string MODE_RATE_LIMITED = 'rate_limited';

    private const string MODE_PERMANENT_FAILURE = 'permanent_failure';

    /**
     * Return a deterministic deal fixture for the requested external ID.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $mode = $request->query('mode', config('services.mock_crm.mode', self::MODE_SUCCESS));
        $modes = [
            self::MODE_SUCCESS,
            self::MODE_RATE_LIMITED,
            self::MODE_PERMANENT_FAILURE,
        ];

        if (! is_string($mode) || ! in_array($mode, $modes, true)) {
            return response()->json([
                'message' => 'Invalid mock API mode.',
                'errors' => [
                    'mode' => ['The mode must be success, rate_limited, or permanent_failure.'],
                ],
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        return match ($mode) {
            self::MODE_SUCCESS => $this->dealResponse($id),
            self::MODE_RATE_LIMITED => $this->rateLimitedResponse($id),
            self::MODE_PERMANENT_FAILURE => response()->json([
                'message' => 'Mock CRM server error.',
            ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR),
        };
    }

    private function rateLimitedResponse(string $id): JsonResponse
    {
        $counterKey = 'mock-api:deals:rate-limited:'.hash('sha256', $id);
        Cache::add($counterKey, 0, now()->addDay());
        $requestNumber = Cache::increment($counterKey);

        if ($requestNumber === false) {
            throw new \RuntimeException('Unable to increment the mock API request counter.');
        }

        if ($requestNumber <= 2) {
            return response()->json([
                'message' => 'Rate limit exceeded.',
            ], JsonResponse::HTTP_TOO_MANY_REQUESTS)
                ->header('Retry-After', '1');
        }

        return $this->dealResponse($id);
    }

    private function dealResponse(string $id): JsonResponse
    {
        $fixtures = config('services.mock_crm.deals', []);
        $fixture = is_array($fixtures) && isset($fixtures[$id]) && is_array($fixtures[$id])
            ? $fixtures[$id]
            : [];
        $deal = array_replace([
            'version' => 1,
            'updated_at' => '2026-10-21T00:00:00.000000Z',
            'title' => "Mock deal {$id}",
            'status' => 'NEW',
            'amount' => '10000.00',
        ], $fixture);

        return response()->json([
            'data' => [
                'id' => $id,
                ...$deal,
            ],
        ]);
    }
}
