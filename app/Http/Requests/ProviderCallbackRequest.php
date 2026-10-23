<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProviderCallbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'provider_event_id'  => ['required', 'string', 'max:255'],
            'provider_reference' => ['required', 'string', 'max:255'],
            'policy_number'      => ['required', 'string', 'max:255'],
            'event_type'         => ['required', 'string', 'max:255'],
        ];
    }
}
