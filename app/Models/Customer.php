<?php

namespace App\Models;

use App\Contracts\Auth\HasMobileLogin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;


class Customer extends Model implements HasMobileLogin
{
    use Notifiable, HasApiTokens;

    protected $fillable = ['name', 'mobile', 'otp', 'otp_expires_at', 'status'];

    public static function findOrCreateByMobile(string $mobile): static
    {
        return static::firstOrCreate(
            ['mobile' => $mobile],
        );
    }
}
