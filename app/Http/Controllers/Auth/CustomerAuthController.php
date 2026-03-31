<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\CheckOtpAction;
use App\Actions\Auth\LoginAction;
use App\Exceptions\TooManyOtpAttemptsException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\CustomerRequest;
use App\Http\Requests\Auth\LoginOtpRequest;
use App\Models\Customer;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class CustomerAuthController extends Controller
{
    public function __construct(protected Customer $customer ){}
    public function login(CustomerRequest $request, LoginAction $loginAction): JsonResponse
    {
        try {
            $mobile = $request->validated()['mobile'];
            $loginAction->handle($mobile, $this->customer);
            Log::channel('auth')->info('OTP sent', ['mobile' => $mobile, 'guard' => 'customer']);
            return $this->successResponse([] ,'I send the OTP to your sms and whatsApp');
        } catch (TooManyOtpAttemptsException $e) {
            Log::channel('auth')->error('To Many OTP Attempt Error', [
                'message' => $e->getMessage()
            ]);
            return $this->errorResponse(
                $e->getMessage(),
                false,
                400,
                ['available_in'=>$e->availableIn]
            );
        }
    }

    public function checkOtp(LoginOtpRequest $request, CheckOtpAction $checkOtpAction) :JsonResponse
    {
        $request = $request->validated();
        try {
            $data = $checkOtpAction->handle($request['mobile'], $request['otp'], $this->customer);
            Log::channel('auth')->info('OTP verified', ['mobile' => $request['mobile'], 'user_id' => $data['user']->id, 'guard' => 'customer']);
            return $this->successResponse($data ,'You Logged In Successfully.');
        } catch (Exception $e) {
            Log::channel('auth')->error('Check OTP Error', [
                'message' => $e->getMessage()
            ]);
            return $this->errorResponse(
                $e->getMessage(),
            );
        }
    }
}
