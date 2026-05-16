<?php

namespace App\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    protected $middleware = [
        \Illuminate\Foundation\Http\Middleware\CheckForMaintenanceMode::class,
        \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
        \Illuminate\Foundation\Http\Middleware\TrimStrings::class,
        \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
        // \Illuminate\Http\Middleware\HandleCors::class, // CORS
        \Illuminate\Http\Middleware\HandleCors::class,
    ];

    protected $middlewareGroups = [
        'web' => [
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],

        'api' => [
            'throttle:api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            // \Illuminate\Http\Middleware\HandleCors::class, // CORS
        ],
    ];

    // protected $middlewareAliases = [
    //     'auth' => \Illuminate\Auth\Middleware\Authenticate::class,

    //     'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
    //     'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
    //     'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,

    //     'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
    // ];
}
