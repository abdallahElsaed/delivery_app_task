<?php

use App\Http\Controllers\TestRedisController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/test-redis', [TestRedisController::class, 'testRedis']);
