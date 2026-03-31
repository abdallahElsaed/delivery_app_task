<?php

namespace App\Jobs\Notification;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Twilio\Rest\Client;


class SmsNotificationJop implements ShouldQueue
{
    use Queueable;


    public function __construct(
        protected string $mobile,
        protected int $otp
    ) {
        $this->onQueue('notification');
    }

    public function handle(Client $twilioClient): void
    {
        try {
            $twilioClient->messages->create($this->mobile, [
                'from' => config('services.twilio.sms_from'),
                'body' => "Your OTP is: {$this->otp}. It expires in 5 minutes.",
            ]);
            Log::channel('notification')->info('SMS Notification Success', [
                'mobile' => $this->mobile,
                'channel' => 'sms',
            ]);
        } catch (\Throwable $th) {
            Log::channel('notification')->error('SMS Notification Error', [
                'mobile' => $this->mobile,
                'channel' => 'sms',
                'error' => $th->getMessage(),
            ]);
        }
    }
}
