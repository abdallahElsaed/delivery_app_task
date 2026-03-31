<?php

namespace App\Enums\Import;

enum ImportBatchRowStatus : string
{
    case FAILED   = 'failed';
    case RETRYING = 'retrying';
    case RESOLVED = 'resolved';
}

