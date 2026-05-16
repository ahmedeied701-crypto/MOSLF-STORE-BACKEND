<?php

declare(strict_types=1);

// =============================================================================
// app/Console/Commands/Testing/CartSetQuantityWorker.php
// =============================================================================

namespace App\Console\Commands\Testing;

use App\Services\Cart\CartService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

/**
 * [TEST ONLY] Single-invocation set-quantity worker for concurrency simulation.
 */
final class CartSetQuantityWorker extends Command
{
    protected $signature = 'test:cart-set-quantity-worker
        {--cart-id=    : UUID of the target cart}
        {--variant-id= : ID of the ProductVariation}
        {--quantity=1  : Absolute quantity to set}
        {--run-id=     : Test run UUID for log-line correlation only}';

    protected $description = '[TEST ONLY] Calls CartService::setItemQuantity() once — used for concurrency simulation.';

    public function handle(CartService $service): int
    {
        $this->guardTestOnly();

        $cartId    = (string) $this->option('cart-id');
        $variantId = (string) $this->option('variant-id');
        $quantity  = (int)   $this->option('quantity');
        $runId     = (string) ($this->option('run-id') ?? 'unknown');

        $request = Request::create('/internal/concurrency-test/set-quantity', 'PATCH');
        $request->headers->set('X-Guest-Cart-ID', $cartId);

        try {
            $service->setItemQuantity($request, $variantId, $quantity);

            $this->line(
                "[run:{$runId}] set-qty OK  variant={$variantId} qty={$quantity}"
            );

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("ERROR_MESSAGE: " . $e->getMessage());
            $this->error("TRACE: " . $e->getTraceAsString());
            return self::FAILURE;
        }
    }

    private function guardTestOnly(): void
    {
        $isProduction     = app()->isProduction();
        $explicitlyEnabled = filter_var(
            env('CART_CONCURRENCY_TESTS_ENABLED', false),
            FILTER_VALIDATE_BOOLEAN,
        );

        if ($isProduction && ! $explicitlyEnabled) {
            $this->error('This command must not run in production.');
            exit(2);
        }
    }
}
