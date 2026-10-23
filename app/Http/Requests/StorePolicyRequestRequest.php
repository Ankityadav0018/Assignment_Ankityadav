<?php

namespace App\Http\Requests;

use App\Enums\Destination;
use App\Services\PremiumCalculator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the payload for POST /api/policy-requests.
 *
 * Validation responsibilities:
 *  - All required fields present and correctly typed.
 *  - plan_code restricted to supported plans.
 *  - sum_insured restricted to supported coverage tiers.
 *  - date_of_birth strictly in the past.
 *  - trip_end on or after trip_start.
 *
 * Premium arithmetic is NOT performed here; the controller does that after
 * validation passes so that any DomainException surfaces cleanly.
 */
class StorePolicyRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authentication/authorization handled by middleware; always true here.
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        if ($this->has('destination') && is_string($this->destination)) {
            $merge['destination'] = strtoupper(trim($this->destination));
        }
        if ($this->has('plan_code') && is_string($this->plan_code)) {
            $merge['plan_code'] = strtoupper(trim($this->plan_code));
        }
        if (! empty($merge)) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        return [
            'client_request_id' => ['required', 'string', 'max:255'],
            'agent_id'          => ['required', 'string', 'max:255'],

            'customer_name'     => ['required', 'string', 'max:255'],
            'date_of_birth'     => ['required', 'date_format:Y-m-d', 'before:today'],

            'destination'       => ['required', 'string', Rule::in(
                array_column(Destination::cases(), 'value')
            )],

            'trip_start'        => ['required', 'date_format:Y-m-d'],
            'trip_end'          => ['required', 'date_format:Y-m-d', 'after_or_equal:trip_start'],

            'plan_code'         => ['required', 'string', Rule::in(
                array_keys(PremiumCalculator::SUPPORTED_PLANS)
            )],

            'sum_insured'       => ['required', 'integer', Rule::in(
                array_keys(PremiumCalculator::SUPPORTED_COVERAGES)
            )],
        ];
    }

    public function messages(): array
    {
        return [
            'date_of_birth.before'             => 'The date of birth must be in the past.',
            'trip_end.after_or_equal'          => 'The trip end date must be on or after the trip start date.',
            'plan_code.in'                     => 'The plan code must be one of: ' . implode(', ', array_keys(PremiumCalculator::SUPPORTED_PLANS)) . '.',
            'sum_insured.in'                   => 'The sum insured must be one of: ' . implode(', ', array_keys(PremiumCalculator::SUPPORTED_COVERAGES)) . '.',
        ];
    }
}
