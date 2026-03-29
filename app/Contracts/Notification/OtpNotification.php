<?php

namespace App\Contracts\Notification;

interface OtpNotification
{
    public function send(string $mobile, int $otp):void ;
}
