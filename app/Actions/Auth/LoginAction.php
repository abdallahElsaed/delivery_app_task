<?php

namespace App\Actions\Auth;

use App\Actions\Auth\OtpRateLimiter;
use App\Contracts\Auth\HasMobileLogin;
use App\Jobs\Notification\SmsNotificationJop;
use App\Jobs\Notification\WhatsAppNotificationJop;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Twilio\Rest\Client;


class LoginAction
{
    public function __construct(protected OtpRateLimiter $rateLimiter) {}

    public function handle(string $mobile, HasMobileLogin $model)
    {
        // 1. Check rate limit — throws TooManyOtpAttemptsException if exceeded
        $this->rateLimiter->attempt($mobile);

        // 2. Find or create user by mobile
        $model::findOrCreateByMobile($mobile);

        // 3. Generate 4-digit OTP
        $otp = rand(1000, 9999);
        Log::channel('notification')->info('OTP Code', [
            'otp' => $otp
        ]);
        // 4. Store OTP in cache with 5 min expiration
        Cache::put("login-otp_{$mobile}", $otp, now()->addMinutes(5));

        // 5. Ensure + prefix
        $mobile = str_starts_with($mobile, '+') ? $mobile : "+{$mobile}";
        // 6. Dispatch notification jobs (SMS, WhatsApp)
        SmsNotificationJop::dispatch($mobile, $otp);
        WhatsAppNotificationJop::dispatch($mobile, $otp);
    }
}
