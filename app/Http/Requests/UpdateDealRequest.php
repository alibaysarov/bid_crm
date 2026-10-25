<?php

namespace App\Http\Requests;

use App\Enums\DealStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate the editable fields provided when a deal is updated.
 */
class UpdateDealRequest extends FormRequest
{
    /**
     * Allow deal updates; authorization can be added with application authentication.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Return conditional rules for fields that may be edited.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'external_id' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
                Rule::unique('deals', 'external_id')->ignore($this->route('deal')),
            ],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'status' => ['sometimes', 'required', 'string', Rule::in(DealStatus::values())],
            'amount' => ['sometimes', 'required', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
            'assigned_to_user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
        ];
    }
}
