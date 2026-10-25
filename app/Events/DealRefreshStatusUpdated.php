<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DealRefreshStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>|null  $deal
     */
    public function __construct(
        public int $userId,
        public int $dealId,
        public bool $success,
        public string $message,
        public string $status,
        public ?array $deal = null,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('App.Models.User.'.$this->userId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'deal.refresh.updated';
    }

    /**
     * @return array{
     *     deal_id: int,
     *     success: bool,
     *     message: string,
     *     status: string,
     *     deal: array<string, mixed>|null
     * }
     */
    public function broadcastWith(): array
    {
        return [
            'deal_id' => $this->dealId,
            'success' => $this->success,
            'message' => $this->message,
            'status' => $this->status,
            'deal' => $this->deal,
        ];
    }
}
