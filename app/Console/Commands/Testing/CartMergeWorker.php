<?php

declare(strict_types=1);

// =============================================================================
// app/Console/Commands/Testing/CartMergeWorker.php
// =============================================================================

namespace App\Console\Commands\Testing;

use App\Models\User;
use App\Services\Cart\CartService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

/**
 * [TEST ONLY] Single-invocation merge worker for concurrency simulation.
 *
 * Non-destructive merge contract
 * ──────────────────────────────
 * The CartService::mergeGuestCart() implementation must set
 * guest cart_items.quantity = 0 (or equivalent) rather than deleting rows.
 * This keeps the guest cart row in the DB for the test's positive assertion
 * while still proving the merge transferred the items.
 *
 * Exit codes
 * ──────────
 *  0 → merge succeeded or guest cart was already consumed (graceful no-op).
 *  1 → unexpected exception (treated as a test failure by the test suite).
 *  2 → production guard triggered.
 */
final class CartMergeWorker extends Command
{
    protected $signature = 'test:cart-merge-worker
        {--user-id=       : ID of the authenticated user}
        {--guest-cart-id= : UUID of the guest cart to merge}
        {--run-id=        : Test run UUID for log-line correlation only}';

    protected $description = '[TEST ONLY] Calls CartService::mergeGuestCart() once — used for concurrency simulation.';

    public function handle(CartService $service): int
    {
        $this->guardTestOnly();

        $userId      = (int)    $this->option('user-id');
        $guestCartId = (string) $this->option('guest-cart-id');
        $runId       = (string) ($this->option('run-id') ?? 'unknown');

        $user = User::find($userId);

        if ($user === null) {
            $this->error("[run:{$runId}] User #{$userId} not found.");
            return self::FAILURE;
        }

        $request = Request::create('/internal/concurrency-test/merge', 'POST', [
            'guest_cart_id' => $guestCartId,
        ]);

        // Bind the user so CartService sees $request->user().
        $request->setUserResolver(fn() => $user);

        try {
            $result = $service->mergeGuestCart($request);

            if (empty($result)) {
                // Empty result = guest cart already consumed by a concurrent
                // winner.  This is the expected graceful no-op path.
                $this->line(
                    "[run:{$runId}] merge NO-OP (guest cart already consumed) guest={$guestCartId}"
                );
                return self::SUCCESS;
            }

            $this->line("[run:{$runId}] merge OK  guest={$guestCartId}");
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
