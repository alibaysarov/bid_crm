<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $event_id
 * @property string|null $event_type
 * @property Carbon|null $event_time
 * @property Carbon|null $processing_started_at
 * @property array{id: string, version: int, updated_at: string, title: string, status: string, amount: int|float|string}|null $data
 * @property string|null $status
 * @property string|null $error
 */
#[Fillable(['event_id', 'event_type', 'event_time', 'processing_started_at', 'data', 'status', 'error'])]
class Event extends Model
{
    public $timestamps = false;

    public const CREATED = 'CREATED';

    public const SUCCESS = 'SUCCESS';

    public const SKIPPED = 'SKIPPED';

    public const PROCESSING = 'PROCESSING';

    public const FAILED = 'FAILED';

    /**
     * Return the casts applied to event attributes.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_time' => 'datetime',
            'processing_started_at' => 'datetime',
            'data' => 'array',
            'id' => 'integer',
        ];
    }
}
