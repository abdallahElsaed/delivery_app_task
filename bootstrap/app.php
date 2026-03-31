<?php

use App\Http\Middleware\LogApiRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('web')
            ->prefix('admin')
            ->group(base_path('routes/admin.php'));
        }
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(replace: [
            ''=> '',
        ]);
        $middleware->api(append: [
            LogApiRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*'));

        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'data'    => null,
                    'message' => $e->getMessage(),
                    'success' => false,
                    'errors'  => $e->errors(),
                ], 422);
            }
        });

        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'data'    => null,
                    'message' => 'Unauthenticated.',
                    'success' => false,
                ], 401);
            }
        });

        $exceptions->render(function (\App\Exceptions\TooManyOtpAttemptsException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'data'    => null,
                    'message' => $e->getMessage(),
                    'success' => false,
                    'retry_after' => $e->availableIn,
                ], 429);
            }
        });

        $exceptions->render(function (\Illuminate\Database\Eloquent\ModelNotFoundException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'data'    => null,
                    'message' => 'Resource not found.',
                    'success' => false,
                ], 404);
            }
        });

        $exceptions->render(function (\Throwable $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'data'    => null,
                    'message' => app()->environment('production')
                        ? 'An unexpected error occurred.'
                        : $e->getMessage(),
                    'success' => false,
                ], 500);
            }
        });
    })->create();
