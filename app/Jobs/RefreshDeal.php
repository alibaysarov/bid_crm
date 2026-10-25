<?php

namespace App\Jobs;

use App\ConflictResolver\ExternalVersionResolver;
use App\Enums\DealStatus;
use App\Models\Deal;
use App\Models\OutboxMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

class RefreshDeal implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [1, 5];

    public int $timeout = 60;

    public string $refreshId;

    public function __construct(
        public int $dealId,
        public int $userId,
    ) {
        $this->refreshId = (string) Str::uuid();
    }

    public function handle(ExternalVersionResolver $externalVersionResolver): void
    {
        $deal = DB::transaction(function (): Deal {
            $deal = Deal::query()->whereKey($this->dealId)->lockForUpdate()->firstOrFail();
            $deal->update([
                'external_refresh_status' => 'processing',
                'external_refresh_error' => null,
                'external_refresh_updated_at' => now(),
            ]);

            return Deal::query()->findOrFail($deal->id);
        });

        if ($deal->external_id === null) {
            throw new RuntimeException('The deal is not linked to an external CRM.');
        }

        $baseUrl = rtrim((string) config('services.mock_crm.url'), '/');

        $response = Http::baseUrl($baseUrl)
            ->acceptJson()
            ->timeout(10)
            ->get('/mock-api/deals/'.rawurlencode($deal->external_id))
            ->throw();

        $payload = $response->json('data');

        if (! is_array($payload)) {
            throw new RuntimeException('The external CRM returned an invalid response.');
        }

        $attributes = $this->validate($payload);

        if ($attributes['id'] !== $deal->external_id) {
            throw new RuntimeException('The external CRM returned a different deal.');
        }

        $externalId = $deal->external_id;

        $outboxMessageId = DB::transaction(function () use ($attributes, $externalId, $externalVersionResolver): int {
            $currentDeal = Deal::query()
                ->whereKey($this->dealId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($currentDeal->external_id !== $externalId) {
                throw new RuntimeException('The deal external ID changed during refresh.');
            }

            $isNewerVersion = $externalVersionResolver->resolve($currentDeal, $attributes);
            $currentDeal->update([
                'external_refresh_status' => 'succeeded',
                'external_refresh_error' => null,
                'external_refresh_updated_at' => now(),
            ]);

            return $this->recordOutboxMessage(
                $currentDeal,
                'succeeded',
                true,
                $isNewerVersion
                    ? 'Сделка обновлена из внешней CRM.'
                    : 'В сделке уже актуальная версия CRM.',
            )->id;
        });

        $this->dispatchOutboxMessages([$outboxMessageId]);
    }

    public function failed(?Throwable $exception): void
    {
        $outboxMessageId = DB::transaction(function (): ?int {
            $deal = Deal::query()->whereKey($this->dealId)->lockForUpdate()->first();

            if ($deal === null || $deal->external_refresh_status === 'succeeded') {
                return null;
            }

            $deal->update([
                'external_refresh_status' => 'failed',
                'external_refresh_error' => 'Не удалось обновить сделку после нескольких попыток.',
                'external_refresh_updated_at' => now(),
            ]);

            return $this->recordOutboxMessage(
                $deal,
                'failed',
                false,
                'Не удалось обновить сделку из внешней CRM.',
            )->id;
        });

        if ($outboxMessageId !== null) {
            $this->dispatchOutboxMessages([$outboxMessageId]);
        }

        if ($exception !== null) {
            Log::warning('Deal refresh exhausted its queue attempts.', [
                'deal_id' => $this->dealId,
                'exception' => $exception,
            ]);
        }
    }

    private function recordOutboxMessage(
        Deal $deal,
        string $stage,
        bool $success,
        string $message,
    ): OutboxMessage {
        $eventKey = $this->refreshId.':'.$stage;

        return OutboxMessage::query()->firstOrCreate(
            ['event_key' => $eventKey],
            [
                'event_type' => 'deal.refresh.updated',
                'payload' => [
                    'user_id' => $this->userId,
                    'deal_id' => $deal->id,
                    'success' => $success,
                    'message' => $message,
                    'status' => $stage,
                    'deal' => $this->refreshStatusPayload($deal),
                ],
                'status' => OutboxMessage::PENDING,
                'available_at' => now(),
            ],
        );
    }

    /**
     * @param  array<int, int>|null  $ids
     */
    private function dispatchOutboxMessages(?array $ids = null): void
    {
        $query = OutboxMessage::query()->where('status', OutboxMessage::PENDING);

        if ($ids !== null) {
            $query->whereIn('id', $ids);
        } else {
            $query->where('available_at', '<=', now());
        }

        $query->pluck('id')->each(function (int $id): void {
            try {
                DeliverOutboxMessage::dispatch($id);
            } catch (Throwable $exception) {
                Log::warning('Unable to dispatch an outbox message; it remains pending.', [
                    'outbox_message_id' => $id,
                    'exception' => $exception,
                ]);
            }
        });
    }

    /**
     * @return array{
     *     title: string,
     *     status: string,
     *     amount: string,
     *     external_version: int|null,
     *     external_updated_at: string|null,
     *     external_refresh_status: string|null,
     *     external_refresh_error: string|null,
     *     external_refresh_updated_at: string|null,
     *     updated_at: string|null
     * }
     */
    private function refreshStatusPayload(Deal $deal): array
    {
        return [
            'title' => $deal->title,
            'status' => $deal->status,
            'amount' => $deal->amount,
            'external_version' => $deal->external_version,
            'external_updated_at' => $deal->external_updated_at?->toISOString(),
            'external_refresh_status' => $deal->external_refresh_status,
            'external_refresh_error' => $deal->external_refresh_error,
            'external_refresh_updated_at' => $deal->external_refresh_updated_at?->toISOString(),
            'updated_at' => $deal->updated_at?->toISOString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     id: string,
     *     version: int,
     *     updated_at: string,
     *     title: string,
     *     status: string,
     *     amount: string
     * }
     */
    private function validate(array $payload): array
    {
        $validated = Validator::make($payload, [
            'id' => ['required', 'string', 'max:255'],
            'version' => ['required', 'integer', 'min:1'],
            'updated_at' => ['required', 'date'],
            'title' => ['required', 'string', 'max:255'],
            'status' => ['required', 'string', Rule::in(DealStatus::values())],
            'amount' => ['required', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
        ])->validate();

        return [
            'id' => (string) $validated['id'],
            'version' => (int) $validated['version'],
            'updated_at' => (string) $validated['updated_at'],
            'title' => (string) $validated['title'],
            'status' => (string) $validated['status'],
            'amount' => (string) $validated['amount'],
        ];
    }
}
