<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;


return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        channels: __DIR__.'/../routes/channels.php',
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
        then: function () {
            
            Route::middleware(['api', 'auth:sanctum', 'role:admin'])
                ->prefix('api/v1')
                ->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {

        $middleware->alias([
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'api.context' => \App\Http\Middleware\SetApiContext::class,
        ]);
    })

    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function ($response, \Throwable $e, $request) {

            if ($e instanceof \Illuminate\Auth\AuthenticationException) {

                return response()->json([
                    'error' => [
                        'code' => 'Not Found',
                        // 'code' => 'UNAUTHENTICATED',
                        'message' => 'You must be logged in to access this resource',
                    ]
                ], 401);
            }

            return $response;
        });
    })->create();
