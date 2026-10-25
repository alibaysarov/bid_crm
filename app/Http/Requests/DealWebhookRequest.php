<?php

namespace App\Http\Requests;

use App\Enums\DealStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate a simplified deal event received from an external CRM.
 */
class DealWebhookRequest extends FormRequest
{
    /**
     * Allow incoming webhook events.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Return rules for the webhook event and its deal data.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'event_id' => ['required', 'string', 'max:255'],
            'event_time' => ['required', 'date'],
            'deal.id' => ['required', 'string', 'max:255'],
            'deal.version' => ['required', 'integer', 'min:1'],
            'deal.updated_at' => ['required', 'date'],
            'deal.title' => ['required', 'string', 'max:255'],
            'deal.status' => ['required', 'string', Rule::in(DealStatus::values())],
            'deal.amount' => ['required', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
        ];
    }
}
