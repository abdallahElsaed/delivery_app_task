<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\CheckOtpAction;
use App\Actions\Auth\LoginAction;
use App\Exceptions\TooManyOtpAttemptsException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\DriverRequest;
use App\Http\Requests\Auth\LoginOtpRequest;
use App\Models\Driver;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DriversAuthController extends Controller
{

    public function __construct(protected Driver $driver) {}
    public function login(DriverRequest $request, LoginAction $loginAction): JsonResponse
    {
        try {
            $loginAction->handle($request->validated()['mobile'], $this->driver);
            return $this->successResponse([], 'I send the OTP to your sms and whatsApp');
        } catch (TooManyOtpAttemptsException $e) {
            Log::channel('auth')->error('To Many OTP Attempt Error', [
                'message' => $e->getMessage()
            ]);
            return $this->errorResponse(
                $e->getMessage(),
                false,
                400,
                ['available_in' => $e->availableIn]
            );
        }
    }

    public function checkOtp(LoginOtpRequest $request, CheckOtpAction $checkOtpAction): JsonResponse
    {
        $request = $request->validated();
        try {
            $data = $checkOtpAction->handle($request['mobile'], $request['otp'], $this->driver);
            return $this->successResponse($data, 'You Logged In Successfully.');
        } catch (Exception $e) {
            Log::channel('auth')->error('Check OTP Error', [
                'message' => $e->getMessage()
            ]);
            return $this->errorResponse(
                $e->getMessage(),
            );
        }
    }

    public function logout()
    {
        try {
            /** @var \App\Models\Driver|null $driver */
            $driver = request()->user('driver');

            if (!$driver) {
                return $this->errorResponse('Unauthenticated.', false, 401);
            }

            $token = $driver->currentAccessToken();
            if ($token) {
                $driver->tokens()->whereKey($token->id)->delete();
            } else {
                // If the request isn't authenticated via a Sanctum token,
                // fall back to revoking all tokens for safety.
                $driver->tokens()->delete();
            }

            return $this->successResponse([], 'Logged out successfully.');
        } catch (Exception $e) {
            Log::channel('auth')->error('Driver Logout Error', [
                'message' => $e->getMessage(),
            ]);

            return $this->errorResponse($e->getMessage());
        }
    }
}
