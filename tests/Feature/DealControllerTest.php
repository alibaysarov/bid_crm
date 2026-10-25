<?php

use App\Enums\DealStatus;
use App\Models\Deal;
use Illuminate\Support\Facades\DB;

describe('deal CRUD endpoints', function () {
    it('requires an authenticated session for deal access', function () {
        $this->getJson('/api/deals')->assertUnauthorized();
    });

    it('creates a deal from validated manual fields', function () {
        $user = authenticateApiUser($this);

        $response = $this->postJson('/api/deals', [
            'title' => 'Quarterly subscription',
            'status' => DealStatus::New->value,
            'amount' => '125.50',
            'external_id' => 'external-123',
            'external_version' => 7,
            'external_updated_at' => '2026-10-20T10:00:00Z',
            'created_by_user_id' => 42,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.title', 'Quarterly subscription')
            ->assertJsonPath('data.amount', '125.50')
            ->assertJsonPath('data.external_id', 'external-123')
            ->assertJsonPath('data.external_version', null)
            ->assertJsonPath('data.created_by_user_id', $user->id);

        $this->assertDatabaseHas('deals', [
            'title' => 'Quarterly subscription',
            'status' => DealStatus::New->value,
            'amount' => '125.50',
            'external_id' => 'external-123',
            'external_version' => null,
            'created_by_user_id' => $user->id,
        ]);
    });

    it('shows one existing deal', function () {
        authenticateApiUser($this);
        $deal = Deal::factory()->create([
            'title' => 'Renewal',
            'status' => DealStatus::Won->value,
        ]);

        $this->getJson("/api/deals/{$deal->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $deal->id)
            ->assertJsonPath('data.title', 'Renewal');
    });

    it('updates the external link but ignores external synchronization metadata', function () {
        authenticateApiUser($this);
        $deal = Deal::factory()->create([
            'external_id' => 'external-123',
            'external_version' => 7,
            'external_updated_at' => '2026-10-19T12:00:00Z',
        ]);

        $this->patchJson("/api/deals/{$deal->id}", [
            'title' => 'Updated title',
            'external_id' => 'updated-external-id',
            'external_version' => 99,
            'external_updated_at' => '2026-10-20T12:00:00Z',
        ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Updated title')
            ->assertJsonPath('data.external_id', 'updated-external-id')
            ->assertJsonPath('data.external_version', 7)
            ->assertJsonPath('data.external_updated_at', '2026-10-19T12:00:00.000000Z');

        $this->assertDatabaseHas('deals', [
            'id' => $deal->id,
            'title' => 'Updated title',
            'external_id' => 'updated-external-id',
            'external_version' => 7,
        ]);
    });

    it('rejects an external ID already linked to another deal', function () {
        authenticateApiUser($this);
        Deal::factory()->create(['external_id' => 'external-123']);

        $this->postJson('/api/deals', [
            'title' => 'Duplicate external deal',
            'status' => DealStatus::New->value,
            'amount' => '125.50',
            'external_id' => 'external-123',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['external_id']);

        $this->assertDatabaseCount('deals', 1);
    });

    it('deletes a deal', function () {
        authenticateApiUser($this);
        $deal = Deal::factory()->create();

        $this->deleteJson("/api/deals/{$deal->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('deals', ['id' => $deal->id]);
    });

    it('returns 404 for a missing deal', function () {
        authenticateApiUser($this);
        $this->getJson('/api/deals/999999')
            ->assertNotFound();
    });
});

describe('deal list query parameters', function () {
    it('filters deals by status', function () {
        authenticateApiUser($this);
        $newDeal = Deal::factory()->create(['status' => DealStatus::New->value]);
        Deal::factory()->create(['status' => DealStatus::Won->value]);

        $this->getJson('/api/deals?status=NEW')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $newDeal->id);
    });

    it('searches deal titles and external IDs without case sensitivity', function () {
        authenticateApiUser($this);
        $titleMatch = Deal::factory()->create([
            'title' => 'Quarterly Renewal',
            'external_id' => null,
        ]);
        $externalIdMatch = Deal::factory()->create([
            'title' => 'Unrelated deal',
            'external_id' => 'CRM-AbC-123',
        ]);
        Deal::factory()->create([
            'title' => 'Different opportunity',
            'external_id' => 'crm-other',
        ]);

        $this->getJson('/api/deals?search=%20qUaRtErLy%20')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $titleMatch->id);

        $this->getJson('/api/deals?search=abc-123')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $externalIdMatch->id);
    });

    it('rejects deal search terms longer than one hundred characters', function () {
        authenticateApiUser($this);

        $this->getJson('/api/deals?search='.str_repeat('a', 101))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('search');
    });

    it('rejects an empty deal search term', function () {
        authenticateApiUser($this);

        $this->getJson('/api/deals?search=')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('search');
    });

    it('treats SQL wildcard characters in deal searches literally', function () {
        authenticateApiUser($this);
        $matchingDeal = Deal::factory()->create([
            'title' => 'Renewal 100% complete',
        ]);
        Deal::factory()->create([
            'title' => 'Renewal 100X complete',
        ]);

        $this->getJson('/api/deals?search=%25')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matchingDeal->id);
    });

    it('sorts deals and paginates with stable metadata', function () {
        authenticateApiUser($this);
        $firstDeal = Deal::factory()->create([
            'amount' => '100.00',
            'status' => DealStatus::New->value,
        ]);
        Deal::factory()->create([
            'amount' => '300.00',
            'status' => DealStatus::New->value,
        ]);
        Deal::factory()->create([
            'amount' => '200.00',
            'status' => DealStatus::New->value,
        ]);

        $this->getJson('/api/deals?status=NEW&sort=amount&direction=desc&page=2&per_page=2')
            ->assertOk()
            ->assertJsonPath('data.0.id', $firstDeal->id)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2);
    });

    it('rejects a sort column outside the allow-list', function () {
        authenticateApiUser($this);
        $this->getJson('/api/deals?sort=amount%3Bdrop%20table%20deals')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sort');
    });

    it('rejects a page size above the configured maximum', function () {
        authenticateApiUser($this);
        $this->getJson('/api/deals?per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    });
});

describe('deal validation', function () {
    it('requires title, status, and amount when creating a deal', function () {
        authenticateApiUser($this);
        $this->postJson('/api/deals')
            ->assertUnprocessable()
            ->assertJsonPath('errors.title.0', 'The title field is required.')
            ->assertJsonPath('errors.status.0', 'The status field is required.')
            ->assertJsonPath('errors.amount.0', 'The amount field is required.');
    });

    it('rejects a status outside the supported values', function () {
        authenticateApiUser($this);
        $this->postJson('/api/deals', [
            'title' => 'Unsupported status',
            'status' => 'ARCHIVED',
            'amount' => 25,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'The selected status is invalid.');
    });

    it('rejects a negative amount', function () {
        authenticateApiUser($this);
        $this->postJson('/api/deals', [
            'title' => 'Negative amount',
            'status' => DealStatus::New->value,
            'amount' => -1,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', 'The amount field must be at least 0.');
    });

    it('requires an assigned employee to exist', function () {
        authenticateApiUser($this);
        $this->postJson('/api/deals', [
            'title' => 'Unknown assignee',
            'status' => DealStatus::New->value,
            'amount' => 10,
            'assigned_to_user_id' => 999999,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assigned_to_user_id');
    });

    it('clears employee references when an employee is deleted', function () {
        authenticateApiUser($this);
        $userId = DB::table('users')->insertGetId([
            'first_name' => 'Test',
            'last_name' => 'Employee',
            'email' => 'employee@example.test',
            'password' => 'not-used-in-this-test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $deal = Deal::factory()->create([
            'created_by_user_id' => $userId,
            'assigned_to_user_id' => $userId,
        ]);

        DB::table('users')->where('id', $userId)->delete();

        $this->assertDatabaseHas('deals', [
            'id' => $deal->id,
            'created_by_user_id' => null,
            'assigned_to_user_id' => null,
        ]);
    });
});
