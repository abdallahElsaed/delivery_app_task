<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class LogApiRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);

        $response = $next($request);

        $statusCode = $response->getStatusCode();

        if ($statusCode >= 400) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Log::channel('api')->error('API Request Failed', [
                'method'      => $request->method(),
                'url'         => $request->fullUrl(),
                'ip'          => $request->ip(),
                'user_id'     => $request->user()?->id,
                'user_type'   => $request->user() ? get_class($request->user()) : null,
                'status_code' => $statusCode,
                'duration_ms' => $duration,
            ]);
        }

        return $response;
    }
}
