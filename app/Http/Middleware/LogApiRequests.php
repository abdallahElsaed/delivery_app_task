<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class LogApiRequests
{
    private const SENSITIVE_FIELDS = ['otp', 'password', 'password_confirmation', 'token'];

    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);

        $response = $next($request);

        $statusCode = $response->getStatusCode();
        $duration = round((microtime(true) - $startTime) * 1000, 2);

        $context = [
            'method'      => $request->method(),
            'url'         => $request->fullUrl(),
            'ip'          => $request->ip(),
            'user_id'     => $request->user()?->id,
            'user_type'   => $request->user() ? get_class($request->user()) : null,
            'status_code' => $statusCode,
            'duration_ms' => $duration,
            'payload'     => $request->except(self::SENSITIVE_FIELDS),
        ];

        $level = match (true) {
            $statusCode >= 500 => 'error',
            $statusCode >= 400 => 'warning',
            default            => 'info',
        };

        Log::channel('api')->{$level}('API Request', $context);

        return $response;
    }
}
