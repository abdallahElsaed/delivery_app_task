<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

trait ResponseJsonTrait
{
    public function successResponse(mixed $data, string $message = '',bool $success = true, int $status = 200, array $extra = []):JsonResponse
    {
        return $this->jsonResponse($data, $message, $success, $status, $extra);
    }

    public function errorResponse(string $message = '',bool $success = false,int $status = 400, array $extra = []) :JsonResponse
    {
        return $this->jsonResponse(null, $message, $success, $status, $extra);
    }

    private function jsonResponse(mixed $data, string $message,bool $success = false ,int $status, array $extra = []) :JsonResponse
    {
        $body = [
            'data'    => $data,
            'message' => $message,
            'success'  => $success,
        ];

        // Merge any extra fields into the response body
        if (!empty($extra)) {
            $body = array_merge($body, $extra);
        }

        return response()->json($body, $status);
    }
}
