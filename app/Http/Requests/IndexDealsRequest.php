<?php

namespace App\Http\Requests;

use App\Enums\DealStatus;
use App\Filters\DealSort;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate filtering, sorting, and pagination options for the deal list.
 */
class IndexDealsRequest extends FormRequest
{
    /**
     * Allow reading the deal list.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Return rules for supported deal-list query parameters.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', 'string', Rule::in(DealStatus::values())],
            'search' => ['sometimes', 'string', 'min:1', 'max:100'],
            'sort' => ['sometimes', 'string', Rule::in(DealSort::SORTABLE_COLUMNS)],
            'direction' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
