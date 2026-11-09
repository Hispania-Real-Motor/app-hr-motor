<?php

namespace App\Http\Requests;

use App\Models\EnreachErrorEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEnreachErrorEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'error_type' => ['required', 'string', Rule::in([
                EnreachErrorEvent::TYPE_COMMERCIAL_PHONE_NOT_FOUND_IN_SALESFORCE,
            ])],
            'searched_phone' => ['required', 'string', 'max:64'],
            'occurred_at' => [
                'required',
                'date',
                'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/',
            ],
            'direction' => ['required', 'string', Rule::in(['IN', 'OUT'])],
            'other_party_phone' => ['nullable', 'string', 'max:64'],
            'source_event_id' => ['required', 'string', 'max:191'],
            'source_workflow' => ['required', 'string', 'max:100'],
        ];
    }
}
