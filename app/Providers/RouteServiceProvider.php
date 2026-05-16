<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        $this->routes(function () {
            Route::prefix('api')
                ->middleware('api')
                ->group(function () {

                    require base_path('routes/api.php');

                    require base_path('routes/admin.php');
                });


            Route::middleware('web')
                ->namespace($this->namespace) // optional
                ->group(base_path('routes/web.php'));
        });
    }
}
