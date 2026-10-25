<?php

namespace App\Repositories;

use App\Filters\DealFilter;
use App\Filters\DealSort;
use App\Models\Deal;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Provide persistence and paginated query operations for deals.
 *
 * @extends AbstractCrudRepository<Deal>
 */
class DealRepository extends AbstractCrudRepository
{
    /**
     * Configure deal persistence and the query pipeline stages.
     */
    public function __construct(
        private readonly DealFilter $dealFilter,
        private readonly DealSort $dealSort,
    ) {
        parent::__construct(Deal::class);
    }

    /**
     * Filter, sort, and paginate deals using validated request options.
     *
     * @param  array<string, mixed>  $options
     * @return LengthAwarePaginator<int, Deal>
     */
    public function paginate(array $options): LengthAwarePaginator
    {
        $query = $this->query();
        $query = $this->dealFilter->apply($query, $options);
        $query = $this->dealSort->apply($query, $options);

        return $query
            ->paginate(
                perPage: (int) ($options['per_page'] ?? 15),
                page: (int) ($options['page'] ?? 1),
            )
            ->appends($options);
    }

    /**
     * Create or update a deal received from an external CRM webhook.
     *
     * @param  array{
     *     external_id: string,
     *     external_version: int,
     *     external_updated_at: string,
     *     title: string,
     *     status: string,
     *     amount: int|float|string
     * }  $attributes
     */
    public function syncFromWebhook(array $attributes): Deal
    {
        $externalId = $attributes['external_id'];
        unset($attributes['external_id']);

        return $this->query()->updateOrCreate(
            ['external_id' => $externalId],
            $attributes,
        );
    }
}
