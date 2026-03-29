<?php

namespace App\Exceptions;

use Exception;

class TooManyOtpAttemptsException extends Exception
{
    public function __construct(public readonly int $availableIn)
    {
        parent::__construct("Too many OTP requests. Try again in {$availableIn} seconds.");
    }
}
