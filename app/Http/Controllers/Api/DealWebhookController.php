<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DealWebhookRequest;
use App\Http\Resources\WebhookDealResource;
use App\Jobs\WebhookDeal;
use App\Models\Deal;
use App\Models\Event;
use App\Repositories\EventRepository;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Process deal events received through the external CRM webhook.
 */
class DealWebhookController extends Controller
{
    /**
     * Create an event or report that it is already being processed.
     */
    public function __invoke(
        DealWebhookRequest $request,
        EventRepository $events,
    ): WebhookDealResource {
        $data = $request->validated();
        $received = $events->receive(
            $data['event_id'],
            $data['event_time'],
            $data['deal'],
        );
        $event = $received['event'];
        $eventData = $this->eventData($event);

        if ($received['created'] || in_array($event->status, [Event::CREATED, Event::FAILED], true)) {
            try {
                WebhookDeal::dispatch($eventData, $event->id);
            } catch (\Throwable $exception) {
                $events->markFailed($event->id, $exception->getMessage());
                throw $exception;
            }

            $event->refresh();
        }
        $deal = $this->getDealByEvent($event, $eventData);

        $snapshot = $deal === null
            ? $eventData
            : [
                'id' => $deal->external_id,
                'version' => $deal->external_version,
                'updated_at' => $deal->external_updated_at?->toISOString()
                    ?? $eventData['updated_at'],
                'title' => $deal->title,
                'status' => $deal->status,
                'amount' => $deal->amount,
            ];

        return new WebhookDealResource(
            $this->formatDealSnapshot($snapshot),
            $event,
            $received['created'],
        );
    }

    /**
     * @param  array{id: string, version: int, updated_at: string, title: string, status: string, amount: int|float|string}  $eventData
     */
    private function getDealByEvent(Event $event, array $eventData): ?Deal
    {
        return in_array($event->status, [Event::SUCCESS, Event::SKIPPED], true)
            ? Deal::query()->where('external_id', $eventData['id'])->first()
            : null;
    }

    /**
     * @return array{id: string, version: int, updated_at: string, title: string, status: string, amount: int|float|string}
     */
    private function eventData(Event $event): array
    {
        if ($event->data === null) {
            throw new LogicException('Webhook event is missing its stored deal payload.');
        }

        return $event->data;
    }

    /**
     * @param  array{id: string, version: int, updated_at: string, title: string, status: string, amount: int|float|string}  $deal
     * @return array{id: string, version: int, updated_at: string, title: string, status: string, amount: string}
     */
    private function formatDealSnapshot(array $deal): array
    {
        return [
            'id' => $deal['id'],
            'version' => $deal['version'],
            'updated_at' => Carbon::parse($deal['updated_at'])->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'title' => $deal['title'],
            'status' => $deal['status'],
            'amount' => $this->formatAmount($deal['amount']),
        ];
    }

    private function formatAmount(int|float|string $amount): string
    {
        $amount = (string) $amount;
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return $whole.'.'.str_pad($fraction, 2, '0');
    }
}
