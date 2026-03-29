<?php

namespace App\Providers;

use App\Contracts\Notification\OtpNotification;
use App\Services\Notification\SmsChannel;
use Illuminate\Support\ServiceProvider;
use Twilio\Rest\Client;


class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // $this->app->bind(OtpNotification::class, SmsChannel::class);

        $this->app->singleton(Client::class, function () {
            return new Client(
                config('services.twilio.sid'),
                config('services.twilio.auth_token')
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
