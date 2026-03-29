<?php

namespace App\Contracts\Auth;

interface HasMobileLogin
{
    public static function findOrCreateByMobile(string $mobile): static;
}
