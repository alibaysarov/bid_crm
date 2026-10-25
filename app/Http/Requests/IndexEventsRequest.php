<?php

namespace App\Http\Requests;

use App\Models\Event;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexEventsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'event_type' => ['sometimes', 'string', 'max:64'],
            'status' => [
                'sometimes',
                'string',
                Rule::in([
                    Event::CREATED,
                    Event::PROCESSING,
                    Event::SUCCESS,
                    Event::SKIPPED,
                    Event::FAILED,
                ]),
            ],
            'search' => ['sometimes', 'string', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
