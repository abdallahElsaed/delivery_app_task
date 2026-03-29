<?php

namespace App\Actions\Auth;

use App\Exceptions\TooManyOtpAttemptsException;
use Illuminate\Support\Facades\RateLimiter;

class OtpRateLimiter
{
    private const MAX_ATTEMPTS = 3;
    private const DECAY_MINUTES = 5;

    public function attempt(string $mobile): void
    {
        $key = $this->key($mobile);

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $availableIn = RateLimiter::availableIn($key);
            throw new TooManyOtpAttemptsException($availableIn);
        }

        RateLimiter::hit($key, self::DECAY_MINUTES * 60);
    }

    public function clear(string $mobile): void
    {
        RateLimiter::clear($this->key($mobile));
    }

    public function remaining(string $mobile): int
    {
        return RateLimiter::remaining($this->key($mobile), self::MAX_ATTEMPTS);
    }

    private function key(string $mobile): string
    {
        return "login-otp-send:{$mobile}";
    }
}
