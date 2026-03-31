<?php

use App\Http\Controllers\Auth\CustomerAuthController;
use App\Http\Controllers\Auth\DriversAuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');


//  =========================== Customer Routes ==========================

Route::prefix('customer')->name('customer.')->middleware('throttle:5,10')->group(function () {

    // Public routes (no auth required)
    Route::post('login',     [CustomerAuthController::class, 'login'])->name('login');
    Route::post('check-otp', [CustomerAuthController::class, 'checkOtp'])->name('check-otp');

    // Protected routes (auth required)
    Route::middleware('auth:customer')->group(function () {
        // Route::post('logout', [CustomerAuthController::class, 'logout'])->name('logout');
    });

});

//  =========================== Drivers Routes ==========================

Route::prefix('driver')->name('driver.')->middleware('throttle:5,10')->group(function () {

    // Public routes (no auth required)
    Route::post('login',     [DriversAuthController::class, 'login'])->name('login');
    Route::post('check-otp', [DriversAuthController::class, 'checkOtp'])->name('check-otp');

    // Protected routes (auth required)
    Route::middleware('auth:driver')->group(function () {
        Route::post('logout', [DriversAuthController::class, 'logout'])->name('logout');
    });

});

//  =========================== Test Imports Route ==========================

Route::get('/test-import', function () {
    $processor = app(\App\Service\Imports\ProductImportProcessor::class);
    $processor->handle('/Users/abdallahelsaed/Herd/delivery_app_task/products_test.csv');
    return 'Import dispatched';
});
