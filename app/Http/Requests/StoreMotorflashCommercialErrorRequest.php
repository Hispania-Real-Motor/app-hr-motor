<?php

namespace App\Http\Requests;

use App\Models\MotorflashCommercialErrorEvent;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMotorflashCommercialErrorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'error_type' => ['required', 'string', Rule::in([
                MotorflashCommercialErrorEvent::TYPE_COMMERCIAL_NOT_FOUND_IN_SALESFORCE,
            ])],
            'searched_email' => [
                'required',
                'string',
                'max:191',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (filter_var(trim((string) $value), FILTER_VALIDATE_EMAIL) === false) {
                        $fail('El correo buscado debe tener un formato válido.');
                    }
                },
            ],
            'occurred_at' => [
                'required',
                'date',
                'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/',
            ],
            'source_event_id' => ['required', 'string', 'max:191'],
            'source_workflow' => ['required', 'string', 'max:100'],
        ];
    }
}
