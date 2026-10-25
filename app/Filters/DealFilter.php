<?php

namespace App\Filters;

use App\Models\Deal;
use Illuminate\Database\Eloquent\Builder;

/**
 * Apply deal-list filters while keeping the query available to later pipeline stages.
 */
class DealFilter
{
    /**
     * Apply validated filters and return the query for the next pipeline stage.
     *
     * @param  Builder<Deal>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Deal>
     */
    public function apply(Builder $query, array $filters): Builder
    {
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['search']) && is_string($filters['search'])) {
            $search = mb_strtolower(trim($filters['search']));
            $escapedSearch = str_replace(
                ['!', '%', '_'],
                ['!!', '!%', '!_'],
                $search,
            );
            $pattern = '%'.$escapedSearch.'%';

            $query->where(function (Builder $query) use ($pattern): void {
                $query
                    ->whereRaw("LOWER(title) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(external_id) LIKE ? ESCAPE '!'", [$pattern]);
            });
        }

        return $query;
    }
}
