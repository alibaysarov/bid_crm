<?php

namespace App\Http\Resources;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Serialize a webhook deal snapshot and its processing metadata.
 */
class WebhookDealResource extends JsonResource
{
    /**
     * @param  array{id: string, version: int, updated_at: string, title: string, status: string, amount: string}  $resource
     */
    public function __construct(
        array $resource,
        private readonly Event $event,
        private readonly bool $newEvent,
    ) {
        parent::__construct($resource);
    }

    /**
     * Convert the deal snapshot and event state into the webhook response.
     *
     * @return array{
     *     data: array{id: string, version: int, updated_at: string, title: string, status: string, amount: string},
     *     meta: array{event_id: string|null, processing_status: string, error?: string}
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => [
                'id' => $this->resource['id'],
                'version' => $this->resource['version'],
                'updated_at' => $this->resource['updated_at'],
                'title' => $this->resource['title'],
                'status' => $this->resource['status'],
                'amount' => $this->resource['amount'],
            ],
            'meta' => array_filter([
                'event_id' => $this->event->event_id,
                'processing_status' => $this->processingStatus(),
                'error' => $this->event->status === Event::FAILED ? 'Event processing failed.' : null,
            ]),
        ];
    }

    private function processingStatus(): string
    {
        return match ($this->event->status) {
            Event::CREATED => 'received',
            Event::PROCESSING => 'processing',
            Event::SUCCESS => 'applied',
            Event::SKIPPED => 'skipped',
            Event::FAILED => 'failed',
            default => 'unknown',
        };
    }

    /**
     * Set the HTTP status for a newly created event.
     */
    public function withResponse(Request $request, JsonResponse $response): void
    {
        if ($this->newEvent) {
            $response->setStatusCode(Response::HTTP_CREATED);
        }
    }
}
