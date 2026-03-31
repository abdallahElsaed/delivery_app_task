<?php

namespace App\Jobs\Notification;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Twilio\Rest\Client;


class WhatsAppNotificationJop implements ShouldQueue
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
            $twilioClient->messages->create("whatsapp:{$this->mobile}", [
                'from' => config('services.twilio.whatsapp_from'),
                'body' => "Your OTP is: *{$this->otp}*. It expires in 5 minutes. ",
            ]);
            Log::channel('notification')->info('WhatsApp Notification Success', [
                'mobile' => $this->mobile,
                'channel' => 'whatsapp',
            ]);
        } catch (\Throwable $th) {
            Log::channel('notification')->error('WhatsApp Notification Error', [
                'mobile' => $this->mobile,
                'channel' => 'whatsapp',
                'error' => $th->getMessage(),
            ]);
        }
    }
}
