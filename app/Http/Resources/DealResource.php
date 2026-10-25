<?php

namespace App\Http\Resources;

use App\Models\Deal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serialize a deal and its external synchronization metadata for API responses.
 *
 * @mixin Deal
 */
class DealResource extends JsonResource
{
    /**
     * Convert the deal into its public JSON representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'external_id' => $this->external_id,
            'title' => $this->title,
            'status' => $this->status,
            'amount' => $this->amount,
            'external_updated_at' => $this->external_updated_at?->toISOString(),
            'external_version' => $this->external_version,
            'external_refresh_status' => $this->external_refresh_status,
            'external_refresh_error' => $this->external_refresh_error,
            'external_refresh_updated_at' => $this->external_refresh_updated_at?->toISOString(),
            'created_by_user_id' => $this->created_by_user_id,
            'assigned_to_user_id' => $this->assigned_to_user_id,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
