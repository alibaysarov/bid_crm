<?php

namespace App\Http\Requests;

use App\Enums\DealStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate fields accepted when a deal is created manually.
 */
class StoreDealRequest extends FormRequest
{
    /**
     * Allow deal creation; authorization can be added with application authentication.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Return rules for manually editable deal fields.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'external_id' => ['nullable', 'string', 'max:255', Rule::unique('deals', 'external_id')],
            'title' => ['required', 'string', 'max:255'],
            'status' => ['required', 'string', Rule::in(DealStatus::values())],
            'amount' => ['required', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
