<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A sales deal managed locally and optionally synchronized with an external CRM.
 *
 * @property int $id
 * @property string|null $external_id
 * @property string $title
 * @property string $status
 * @property string $amount
 * @property Carbon|null $external_updated_at
 * @property int|null $external_version
 * @property string|null $external_refresh_status
 * @property string|null $external_refresh_error
 * @property Carbon|null $external_refresh_updated_at
 * @property int|null $created_by_user_id
 * @property int|null $assigned_to_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'external_id',
    'title',
    'status',
    'amount',
    'external_updated_at',
    'external_version',
    'external_refresh_status',
    'external_refresh_error',
    'external_refresh_updated_at',
    'created_by_user_id',
    'assigned_to_user_id',
])]
class Deal extends Model
{
    /** @use HasFactory<DealFactory> */
    use HasFactory;

    /**
     * Return the employee who created the deal, when one is recorded.
     *
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Return the employee responsible for the deal, when one is assigned.
     *
     * @return BelongsTo<User, $this>
     */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /**
     * Return the casts applied to deal attributes.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'external_updated_at' => 'datetime',
            'external_refresh_updated_at' => 'datetime',
            'external_version' => 'integer',
            'created_by_user_id' => 'integer',
            'assigned_to_user_id' => 'integer',
        ];
    }
}
