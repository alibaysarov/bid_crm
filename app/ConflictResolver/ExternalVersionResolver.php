<?php

namespace App\ConflictResolver;

use App\Models\Deal;

class ExternalVersionResolver
{
    /**
     * Apply the CRM attributes when they belong to a newer external version.
     *
     * @param  array{
     *     version: int,
     *     updated_at: string,
     *     title: string,
     *     status: string,
     *     amount: int|float|string
     * }  $attributes
     */
    public function resolve(Deal $deal, array $attributes): bool
    {

        $isNewVersion = $deal->external_version === null || $deal->external_version < $attributes['version'];

        if ($isNewVersion) {
            $deal->update([
                'title' => $attributes['title'],
                'status' => $attributes['status'],
                'amount' => $attributes['amount'],
                'external_updated_at' => $attributes['updated_at'],
                'external_version' => $attributes['version'],
            ]);
        }

        return $isNewVersion;
    }
}
