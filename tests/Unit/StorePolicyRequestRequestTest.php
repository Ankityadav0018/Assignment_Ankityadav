<?php

namespace Tests\Unit;

use App\Http\Requests\StorePolicyRequestRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class StorePolicyRequestRequestTest extends TestCase
{
    private function validate(array $data): \Illuminate\Validation\Validator
    {
        $request = new StorePolicyRequestRequest();

        // Simulate prepareForValidation
        if (isset($data['destination']) && is_string($data['destination'])) {
            $data['destination'] = strtoupper(trim($data['destination']));
        }
        if (isset($data['plan_code']) && is_string($data['plan_code'])) {
            $data['plan_code'] = strtoupper(trim($data['plan_code']));
        }

        return Validator::make($data, $request->rules(), $request->messages());
    }

    private function validPayload(): array
    {
        return [
            'client_request_id' => 'req-12345',
            'agent_id'          => 'agent-99',
            'customer_name'     => 'Jane Doe',
            'date_of_birth'     => '1995-05-15',
            'destination'       => 'INTERNATIONAL',
            'trip_start'        => CarbonImmutable::tomorrow()->toDateString(),
            'trip_end'          => CarbonImmutable::tomorrow()->addDays(5)->toDateString(),
            'plan_code'         => 'PLUS',
            'sum_insured'       => 300000,
        ];
    }

    public function test_valid_payload_passes_validation(): void
    {
        $validator = $this->validate($this->validPayload());
        $this->assertFalse($validator->fails(), 'Expected validation to pass: ' . json_encode($validator->errors()->all()));
    }

    public function test_required_fields_must_be_present(): void
    {
        $validator = $this->validate([]);
        $this->assertTrue($validator->fails());

        $errors = $validator->errors();
        $this->assertTrue($errors->has('client_request_id'));
        $this->assertTrue($errors->has('agent_id'));
        $this->assertTrue($errors->has('customer_name'));
        $this->assertTrue($errors->has('date_of_birth'));
        $this->assertTrue($errors->has('destination'));
        $this->assertTrue($errors->has('trip_start'));
        $this->assertTrue($errors->has('trip_end'));
        $this->assertTrue($errors->has('plan_code'));
        $this->assertTrue($errors->has('sum_insured'));
    }

    public function test_date_of_birth_must_be_in_the_past(): void
    {
        // Today is not in the past
        $payload = $this->validPayload();
        $payload['date_of_birth'] = CarbonImmutable::today()->toDateString();
        $validator = $this->validate($payload);
        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('date_of_birth'));

        // Future date fails
        $payload['date_of_birth'] = CarbonImmutable::tomorrow()->toDateString();
        $validator = $this->validate($payload);
        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('date_of_birth'));

        // Past date succeeds
        $payload['date_of_birth'] = '2000-01-01';
        $validator = $this->validate($payload);
        $this->assertFalse($validator->fails());
    }

    public function test_trip_end_must_be_on_or_after_trip_start(): void
    {
        // trip_end before trip_start fails
        $payload = $this->validPayload();
        $payload['trip_start'] = '2026-11-10';
        $payload['trip_end']   = '2026-11-09';
        $validator = $this->validate($payload);
        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('trip_end'));

        // same-day trip passes (1 day inclusive)
        $payload['trip_end'] = '2026-11-10';
        $validator = $this->validate($payload);
        $this->assertFalse($validator->fails());

        // trip_end after trip_start passes
        $payload['trip_end'] = '2026-11-15';
        $validator = $this->validate($payload);
        $this->assertFalse($validator->fails());
    }

    public function test_plan_code_must_be_in_supported_set(): void
    {
        $payload = $this->validPayload();

        // Valid plans
        foreach (['BASIC', 'PLUS', 'PREMIUM'] as $plan) {
            $payload['plan_code'] = $plan;
            $this->assertFalse($this->validate($payload)->fails(), "Plan {$plan} should be valid");
        }

        // Invalid plans
        $payload['plan_code'] = 'SUPER_PLATINUM';
        $validator = $this->validate($payload);
        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('plan_code'));
    }

    public function test_sum_insured_must_be_in_supported_set(): void
    {
        $payload = $this->validPayload();

        // Valid coverages
        foreach ([100000, 300000, 500000] as $sum) {
            $payload['sum_insured'] = $sum;
            $this->assertFalse($this->validate($payload)->fails(), "Coverage {$sum} should be valid");
        }

        // Unsupported coverage
        $payload['sum_insured'] = 250000;
        $validator = $this->validate($payload);
        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('sum_insured'));
    }

    public function test_sum_insured_1000000_fails_validation(): void
    {
        $payload = $this->validPayload();
        $payload['sum_insured'] = 1000000;
        $validator = $this->validate($payload);
        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('sum_insured'));
    }

    public function test_destination_must_be_domestic_or_international(): void
    {
        $payload = $this->validPayload();

        $payload['destination'] = 'DOMESTIC';
        $this->assertFalse($this->validate($payload)->fails());

        $payload['destination'] = 'INTERNATIONAL';
        $this->assertFalse($this->validate($payload)->fails());

        $payload['destination'] = 'GALACTIC';
        $validator = $this->validate($payload);
        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('destination'));
    }
}
