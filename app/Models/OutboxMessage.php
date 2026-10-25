<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $event_key
 * @property string $event_type
 * @property array<string, mixed> $payload
 * @property string $status
 * @property int $delivery_attempts
 * @property Carbon|null $available_at
 * @property Carbon|null $locked_at
 * @property Carbon|null $sent_at
 * @property string|null $last_error
 */
#[Fillable([
    'event_key',
    'event_type',
    'payload',
    'status',
    'delivery_attempts',
    'available_at',
    'locked_at',
    'sent_at',
    'last_error',
])]
class OutboxMessage extends Model
{
    public const PENDING = 'PENDING';

    public const SENDING = 'SENDING';

    public const SENT = 'SENT';

    /**
     * Return the casts applied to outbox messages.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'delivery_attempts' => 'integer',
            'available_at' => 'datetime',
            'locked_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }
}
