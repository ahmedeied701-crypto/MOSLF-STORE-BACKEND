<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Inventory;
use App\Observers\InventoryObserver;

use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Repositories\ProductRepository;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

use App\Repositories\Public\Contracts\ProductPublicRepositoryInterface;
use App\Repositories\Public\ProductPublicRepository;

use App\Services\Cart\Contracts\CartRepositoryInterface;
use App\Repositories\Cart\CartRepository;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * Binding the interface to the concrete implementation here means:
     *  - Controllers depend only on the interface (SOLID: Dependency Inversion).
     *  - Swapping to a cached or ElasticSearch-backed repository = one line change here.
     */
    public function register(): void
    {
        $this->app->bind(
            CartRepositoryInterface::class,
            CartRepository::class
        );

        // Admin Repository (general products)
        $this->app->bind(
            ProductRepositoryInterface::class,
            ProductRepository::class
        );

        // Public Storefront Repository (ACTIVE ONLY)
        $this->app->bind(
            ProductPublicRepositoryInterface::class,
            ProductPublicRepository::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // HTTPS forcing in production
        if ($this->app->environment('production') && !$this->app->runningInConsole()) {
            URL::forceScheme('https');
        }
        // ================================
        // Cart Concurrency Test Commands
        // ================================
        if (
            ! $this->app->environment('production')
            || filter_var(env('CART_CONCURRENCY_TESTS_ENABLED'), FILTER_VALIDATE_BOOLEAN)
        ) {
            $this->commands([
                \App\Console\Commands\Testing\CartUpsertWorker::class,
                \App\Console\Commands\Testing\CartMergeWorker::class,
                \App\Console\Commands\Testing\CartSetQuantityWorker::class,
            ]);
        }

        \App\Models\Inventory::observe(\App\Observers\InventoryObserver::class);
    }
}
