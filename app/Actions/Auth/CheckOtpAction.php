<?php

namespace App\Actions\Auth;

use App\Contracts\Auth\HasMobileLogin;
use Illuminate\Support\Facades\Cache;

class CheckOtpAction
{
    public function handle(string $mobile, int $otp, HasMobileLogin $model)
    {
        // 1. Check if this mobile exists
        $user = $model->where('mobile', $mobile)->first(['id','mobile','status']);
        if (!$user) {
            throw new \Exception('You must login first.');
        }

        // 2. Check if OTP exists in cache (not expired)
        $cachedOtp = Cache::get("login-otp_{$mobile}");
        if (!$cachedOtp) {
            throw new \Exception('OTP has expired. Please request a new one.');
        }

        // 3. Verify OTP value is correct
        if ((int) $cachedOtp !== $otp) {
            throw new \Exception('Invalid OTP.');
        }

        // 4. OTP is valid — remove it from cache so it can't be reused
        Cache::forget("login-otp_{$mobile}");
        // 5. update the user status
        $user->update(['status' => 'active']);
        // 6. Create Sanctum token
        $token = $user->createToken('mobile-login')->plainTextToken;

        // 7. Return user data and token
        return [
            'user'  => $user,
            'token' => $token,
        ];
    }
}
