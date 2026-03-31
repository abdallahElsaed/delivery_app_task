<?php

namespace App\Actions\Auth;

use App\Actions\Auth\OtpVerificationRateLimiter;
use App\Contracts\Auth\HasMobileLogin;
use Illuminate\Support\Facades\Cache;

class CheckOtpAction
{
    public function __construct(
        private readonly OtpVerificationRateLimiter $rateLimiter,
    ) {}

    public function handle(string $mobile, int $otp, HasMobileLogin $model)
    {
        // 1. Enforce verification rate limit
        $this->rateLimiter->attempt($mobile);

        // 2. Check if this mobile exists
        $user = $model->where('mobile', $mobile)->first(['id','mobile','status']);
        if (!$user) {
            throw new \Exception('You must login first.');
        }

        // 3. Check if OTP exists in cache (not expired)
        $cachedOtp = Cache::get("login-otp_{$mobile}");
        if (!$cachedOtp) {
            throw new \Exception('OTP has expired. Please request a new one.');
        }

        // 4. Verify OTP value is correct
        if ((int) $cachedOtp !== $otp) {
            throw new \Exception('Invalid OTP.');
        }

        // 5. OTP is valid — clear rate limiter and remove OTP from cache
        $this->rateLimiter->clear($mobile);
        Cache::forget("login-otp_{$mobile}");
        // 6. update the user status
        $user->update(['status' => 'active']);
        // 7. Create Sanctum token
        $token = $user->createToken('mobile-login')->plainTextToken;

        // 8. Return user data and token
        return [
            'user'  => $user,
            'token' => $token,
        ];
    }
}
