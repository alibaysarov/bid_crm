<?php

namespace App\Filters;

use App\Models\Deal;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Apply an allow-listed, deterministic ordering to a deal query.
 */
class DealSort
{
    /**
     * Columns that may be selected by a client for sorting.
     *
     * @var list<string>
     */
    public const SORTABLE_COLUMNS = [
        'created_at',
        'updated_at',
        'title',
        'status',
        'amount',
    ];

    /**
     * Apply validated sorting and add a stable ID tie-breaker.
     *
     * @param  Builder<Deal>  $query
     * @param  array<string, mixed>  $options
     * @return Builder<Deal>
     */
    public function apply(Builder $query, array $options): Builder
    {
        $column = $options['sort'] ?? 'created_at';
        $direction = $options['direction'] ?? 'desc';

        if (! is_string($column) || ! in_array($column, self::SORTABLE_COLUMNS, true)) {
            throw new InvalidArgumentException('The requested deal sort column is not supported.');
        }

        if (! is_string($direction) || ! in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('The requested deal sort direction is not supported.');
        }

        $query->orderBy('deals.'.$column, $direction);
        $query->orderByDesc('deals.id');

        return $query;
    }
}
