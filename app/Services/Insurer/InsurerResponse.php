<?php

namespace App\Services\Insurer;

class InsurerResponse
{
    public function __construct(
        public readonly bool $success,
        public readonly string $status,
        public readonly ?string $policyNumber = null,
        public readonly ?string $providerReference = null,
        public readonly ?string $error = null,
        public readonly bool $isRetryable = false,
        public readonly array $rawResponse = [],
    ) {}

    public static function success(
        string $policyNumber,
        string $providerReference,
        array $rawResponse = []
    ): self {
        return new self(
            success: true,
            status: 'ISSUED',
            policyNumber: $policyNumber,
            providerReference: $providerReference,
            rawResponse: $rawResponse ?: [
                'status'             => 'ISSUED',
                'policy_number'      => $policyNumber,
                'provider_reference' => $providerReference,
            ],
        );
    }

    public static function pending(
        string $providerReference,
        ?string $policyNumber = null,
        array $rawResponse = []
    ): self {
        return new self(
            success: true,
            status: 'PENDING',
            policyNumber: $policyNumber,
            providerReference: $providerReference,
            rawResponse: $rawResponse ?: [
                'status'             => 'PENDING',
                'provider_reference' => $providerReference,
            ],
        );
    }

    public static function temporaryFailure(
        string $error = 'TEMPORARY_SERVICE_UNAVAILABLE',
        array $rawResponse = []
    ): self {
        return new self(
            success: false,
            status: 'FAILED',
            error: $error,
            isRetryable: true,
            rawResponse: $rawResponse ?: [
                'status'      => 'FAILED',
                'error'       => $error,
                'is_retryable'=> true,
            ],
        );
    }

    public function toArray(): array
    {
        return [
            'success'            => $this->success,
            'status'             => $this->status,
            'policy_number'      => $this->policyNumber,
            'provider_reference' => $this->providerReference,
            'error'              => $this->error,
            'is_retryable'       => $this->isRetryable,
            'raw_response'       => $this->rawResponse,
        ];
    }
}
