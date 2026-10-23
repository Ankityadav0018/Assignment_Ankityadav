<?php

namespace App\Enums;

enum IssuanceOutcome: string
{
    case Success = 'SUCCESS';
    case Failed  = 'FAILED';
    case Timeout = 'TIMEOUT';
}
