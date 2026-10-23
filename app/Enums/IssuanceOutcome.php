<?php

namespace App\Enums;

enum IssuanceOutcome: string
{
    case Success          = 'SUCCESS';
    case Pending          = 'PENDING';
    case Failed           = 'FAILED';
    case RetryableFailure = 'FAILED_RETRYABLE';
    case Timeout          = 'TIMEOUT';
}
