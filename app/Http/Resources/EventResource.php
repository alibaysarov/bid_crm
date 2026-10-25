<?php

namespace App\Http\Resources;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Event
 */
class EventResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $deal = is_array($this->data) ? $this->data : null;

        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'event_type' => $this->event_type,
            'event_time' => $this->event_time?->toISOString(),
            'status' => $this->status,
            'deal' => $deal === null ? null : [
                'id' => $deal['id'] ?? null,
                'version' => $deal['version'] ?? null,
                'updated_at' => $deal['updated_at'] ?? null,
                'title' => $deal['title'] ?? null,
                'status' => $deal['status'] ?? null,
                'amount' => isset($deal['amount']) ? (string) $deal['amount'] : null,
            ],
            'error' => $this->error,
        ];
    }
}
