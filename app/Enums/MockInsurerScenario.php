<?php

namespace App\Enums;

/**
 * Dev-only simulation scenarios for MockInsurer.
 * Never required in production (defaults to SUCCESS).
 */
enum MockInsurerScenario: string
{
    case Success           = 'SUCCESS';
    case TemporaryFailure  = 'TEMPORARY_FAILURE';
    case TimeoutAfterIssue = 'TIMEOUT_AFTER_ISSUE';
    case DelayedCallback   = 'DELAYED_CALLBACK';
    case DuplicateCallback = 'DUPLICATE_CALLBACK';
}
