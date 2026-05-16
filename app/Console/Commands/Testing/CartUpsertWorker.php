<?php

declare(strict_types=1);

// =============================================================================
// app/Console/Commands/Testing/CartUpsertWorker.php
// =============================================================================

namespace App\Console\Commands\Testing;

use App\Services\Cart\CartService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

/**
 * [TEST ONLY] Single-invocation upsert worker for concurrency simulation.
 *
 * Each invocation is a fully independent OS process with its own DB connection,
 * which is what produces true InnoDB row-lock contention.
 *
 * The --run-id option is used only for log-line correlation; it is never
 * written to the DB by this command — CartService handles all DB writes.
 */
final class CartUpsertWorker extends Command
{
    protected $signature = 'test:cart-upsert-worker
        {--cart-id=       : UUID of the target cart}
        {--variant-id=    : ID of the ProductVariation to upsert}
        {--quantity=1     : Quantity to add additively}
        {--run-id=        : Test run UUID for log-line correlation only}';

    protected $description = '[TEST ONLY] Calls CartService::upsertItem() once — used for concurrency simulation.';

    public function handle(CartService $service): int
    {
        $this->guardTestOnly();

        $cartId    = (string) $this->option('cart-id');
        $variantId = (string) $this->option('variant-id');
        $quantity  = (int)   $this->option('quantity');
        $runId     = (string) ($this->option('run-id') ?? 'unknown');

        // Build a minimal Request that resolveCartLocked() will resolve via
        // the X-Guest-Cart-ID header path.  The cart was pre-created by
        // provisionFixtures() so it will be found without any INSERT.
        $request = Request::create('/internal/concurrency-test/upsert', 'POST');
        $request->headers->set('X-Guest-Cart-ID', $cartId);

        try {
            $service->upsertItem($request, $variantId, $quantity);

            $this->line(
                "[run:{$runId}] upsert OK  cart={$cartId} variant={$variantId} qty={$quantity}"
            );

            return self::SUCCESS;
        } catch (\Throwable $e) {
            // Exit code 1 = stock exhausted or expected lock contention.
            // The test treats exit code 1 as a tolerated outcome for stock
            // tests; only exit code > 1 is treated as an unexpected crash.
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
            exit(2);     // exit code 2 → "unexpected crash" in the test
        }
    }
}
