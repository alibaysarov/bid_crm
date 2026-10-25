<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;

#[Signature('postman:sync')]
#[Description('Sync the generated API collection to Postman')]
class SyncPostmanCollection extends Command
{
    public function handle(): int
    {
        $apiKey = config('services.postman.api_key');
        $collectionUid = config('services.postman.collection_uid');

        if (! is_string($apiKey) || trim($apiKey) === '' || ! is_string($collectionUid) || trim($collectionUid) === '') {
            $this->error('Set POSTMAN_API_KEY and POSTMAN_COLLECTION_UID in your local environment.');

            return self::FAILURE;
        }

        $collectionFile = base_path('postman/Bid CRM API.postman_collection.json');

        if (! is_file($collectionFile)) {
            $this->error('Postman collection file not found. Generate it first with "make postman".');

            return self::FAILURE;
        }

        $collectionJson = file_get_contents($collectionFile);

        if ($collectionJson === false) {
            $this->error('Unable to read the generated Postman collection.');

            return self::FAILURE;
        }

        try {
            $collection = json_decode($collectionJson, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->error('The generated Postman collection is not valid JSON.');

            return self::FAILURE;
        }

        if (! is_array($collection) || ! isset($collection['info'], $collection['item'])) {
            $this->error('The generated JSON does not have the expected Postman collection structure.');

            return self::FAILURE;
        }

        try {
            $response = Http::acceptJson()
                ->withHeaders(['X-Api-Key' => $apiKey])
                ->connectTimeout(5)
                ->timeout(30)
                ->put(
                    'https://api.getpostman.com/collections/'.rawurlencode($collectionUid),
                    ['collection' => $collection],
                );
        } catch (ConnectionException) {
            $this->error('Could not connect to the Postman API.');

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->error("Postman API returned HTTP {$response->status()}; the collection was not synced.");

            return self::FAILURE;
        }

        $this->info('Postman collection synced successfully.');

        return self::SUCCESS;
    }
}
