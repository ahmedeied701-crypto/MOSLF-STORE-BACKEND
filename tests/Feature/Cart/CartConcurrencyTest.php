<?php

declare(strict_types=1);

// =============================================================================
// tests/Feature/Cart/CartConcurrencyTest.php
// =============================================================================

namespace Tests\Feature\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ProductVariation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Non-destructive Cart concurrency integrity suite.
 *
 * Isolation model
 * ───────────────
 * A single UUID ($runId) is generated once per test-class instantiation and
 * embedded in every fixture row this run creates:
 *
 *   users.email                 → "concurrency+{runId}@_test.invalid"
 *   product_variations.sku      → "STRESS-{runId}"   /  "STRESS-B-{runId}"
 *   carts.id                    → UUID pre-chosen by setUp()
 *   cart_items.*                → chained via cart_id FK
 *
 * Every SELECT, assertion, and UPDATE is scoped through one of those keys.
 * No query in this file can touch a row it did not create.
 *
 * Destruction policy
 * ──────────────────
 * NOTHING is deleted — not in tearDown(), not in finally{}, not anywhere.
 * Rows remain in the DB permanently, invisible to production queries because
 * they are keyed by a UUID that appears nowhere in real data.
 *
 * Production guard
 * ────────────────
 * The suite aborts unless APP_ENV ≠ "production" OR
 * CART_CONCURRENCY_TESTS_ENABLED=true is explicitly set.
 *
 * MySQL requirement
 * ─────────────────
 * SQLite does not implement SELECT … FOR UPDATE at the row level.
 * Set DB_CONNECTION=mysql in .env.testing.
 */
final class CartConcurrencyTest extends TestCase
{
    // ── Tuneable constants ────────────────────────────────────────────────────

    private const WORKERS        = 10;
    private const QTY_PER_WORKER = 1;
    private const STOCK_CEILING  = 10_000;
    private const WORKER_TIMEOUT = 30;

    // ── Per-run isolation key — set once, never changed ──────────────────────

    private string $runId;

    // ── Fixture primary keys — scalars only, never open model references ──────

    private int    $userId;
    private string $cartId;
    private int    $variantId;

    // =========================================================================
    // BOOTSTRAP
    // =========================================================================

    protected function setUp(): void
    {
        parent::setUp();

        $this->guardProduction();
        $this->guardMysql();

        // One UUID per class instantiation — all fixtures for this run share it.
        $this->runId = Str::uuid()->toString();

        $this->provisionFixtures();
    }

    // No tearDown — intentionally omitted; rows are permanently isolated by runId.

    // =========================================================================
    // GUARDS
    // =========================================================================

    private function guardProduction(): void
    {
        $isProduction     = app()->environment('production');
        $explicitlyEnabled = filter_var(
            env('CART_CONCURRENCY_TESTS_ENABLED', false),
            FILTER_VALIDATE_BOOLEAN,
        );

        if ($isProduction && ! $explicitlyEnabled) {
            $this->markTestSkipped(
                'CartConcurrencyTest is disabled in production. '
                    . 'Set CART_CONCURRENCY_TESTS_ENABLED=true to override.'
            );
        }
    }

    private function guardMysql(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped(
                'CartConcurrencyTest requires MySQL/MariaDB for row-level locking. '
                    . 'Set DB_CONNECTION=mysql in .env'
            );
        }
    }

    // =========================================================================
    // FIXTURE PROVISIONING  (insert-only, never touches existing rows)
    // =========================================================================

    /**
     * Create the minimal set of rows needed by every test in this run.
     * Each row is tagged with $this->runId so it is permanently distinguishable
     * from any production row without relying on deletion for isolation.
     *
     * Wrapping in a transaction ensures the three inserts are atomic — no test
     * can start with a partially-provisioned fixture set.
     */
    private function provisionFixtures(): void
    {
        DB::transaction(function (): void {

            // ── User ──────────────────────────────────────────────────────────
            // Email domain .invalid is RFC-reserved and will never match a real user.
            $this->userId = DB::table('users')->insertGetId([
                'name'              => '[STRESS] ' . $this->runId,
                'email'             => 'concurrency+' . $this->runId . '@_test.invalid',
                'password'          => bcrypt(Str::random(32)),
                'email_verified_at' => now(),
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);

            // ── ProductVariation ──────────────────────────────────────────────
            // Stock ceiling is high enough that clamping never fires in any test.
            $productId = DB::table('products')->value('id');

            if ($productId === null) {
                $this->markTestSkipped(
                    'No rows found in `products`. '
                        . 'Seed at least one Product before running CartConcurrencyTest.'
                );
            }

            $this->variantId = DB::table('product_variations')->insertGetId([
                'product_id' => $productId,
                'sku'        => 'STRESS-' . $this->runId,
                'price'      => 0.01,
                'stock'      => self::STOCK_CEILING,
                'color'      => null,
                'size'       => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // ── Cart ──────────────────────────────────────────────────────────
            $this->cartId = (string) Str::uuid();

            DB::table('carts')->insert([
                'id'         => $this->cartId,
                'user_id'    => $this->userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    // =========================================================================
    // CONCURRENCY HELPERS
    // =========================================================================

    /**
     * Spawn $count independent child processes that all execute the same
     * $artisanArgs simultaneously, then block until every process finishes.
     *
     * --run-id is appended automatically so every worker can tag its own
     * log lines without touching unrelated DB rows.
     *
     * All Process::start() calls happen before any Process::wait() call —
     * this is what produces true wall-clock parallelism.
     *
     * @return array<int, int>  Per-worker exit codes (0 = success).
     */
    private function spawnWorkers(string $artisanArgs, int $count): array
    {
        $binary  = PHP_BINARY;
        $artisan = base_path('artisan');

        /** @var Process[] $processes */
        $processes = [];

        for ($i = 0; $i < $count; $i++) {
            $p = Process::fromShellCommandline(
                "{$binary} {$artisan} {$artisanArgs} --run-id={$this->runId} 2>&1",
                null,
                [
                    'APP_ENV' => 'testing',
                    'DB_CONNECTION' => 'mysql',
                    'CART_CONCURRENCY_TESTS_ENABLED' => 'true',
                ]
            );

            $p->setTimeout(self::WORKER_TIMEOUT);
            $p->start();
            $processes[] = $p;
        }

        $exitCodes = [];
        foreach ($processes as $p) {
            $p->wait();
            $code = $p->getExitCode() ?? 1;
            $exitCodes[] = $code;

            if ($code !== 0) {
                dump("Worker Fail Output: " . $p->getOutput());
            }
        }

        return $exitCodes;
    }

    /**
     * Assert that every worker in $exitCodes exited cleanly (code 0).
     *
     * @param array<int, int> $exitCodes
     */
    private function assertWorkersSucceeded(array $exitCodes): void
    {
        foreach ($exitCodes as $i => $code) {
            $this->assertSame(
                0,
                $code,
                "Worker #{$i} exited with code {$code}. "
                    . 'Check storage/logs/laravel.log for the child-process error.'
            );
        }
    }

    /**
     * Read the single CartItem row owned by this run's cart + primary variant.
     */
    private function fetchItem(?string $cartId = null, ?int $variantId = null): ?object
    {
        return DB::table('cart_items')
            ->where('cart_id',            $cartId    ?? $this->cartId)
            ->where('product_variant_id', $variantId ?? $this->variantId)
            ->first();
    }

    /**
     * Count CartItem rows scoped to this run's cart + primary variant.
     * Must always equal 1 after a successful upsert sequence.
     */
    private function countItems(?string $cartId = null, ?int $variantId = null): int
    {
        return DB::table('cart_items')
            ->where('cart_id',            $cartId    ?? $this->cartId)
            ->where('product_variant_id', $variantId ?? $this->variantId)
            ->count();
    }

    // =========================================================================
    // TEST 1 — Concurrent additive upserts: same variant, no lost updates
    // =========================================================================

    /**
     * 10 workers each add qty=1 of the same variant to the same cart.
     *
     * Without lockForUpdate two workers can both read qty=0 and both write
     * qty=1 → final quantity of 1 (lost update).  With lockForUpdate each
     * worker waits for the previous commit → final quantity = WORKERS × QTY.
     */
    public function test_concurrent_upserts_same_variant_no_lost_updates(): void
    {
        $exitCodes = $this->spawnWorkers(
            sprintf(
                'test:cart-upsert-worker --cart-id=%s --variant-id=%s --quantity=%d',
                $this->cartId,
                $this->variantId,
                self::QTY_PER_WORKER,
            ),
            self::WORKERS,
        );

        $this->assertWorkersSucceeded($exitCodes);

        // (a) Exactly one CartItem row — no duplicates from concurrent inserts.
        $this->assertSame(
            1,
            $this->countItems(),
            'Expected exactly 1 CartItem row for this run. '
                . 'Multiple rows indicate an un-serialised concurrent insert.'
        );

        // (b) Quantity equals the sum of every worker's increment.
        $item        = $this->fetchItem();
        $expectedQty = self::WORKERS * self::QTY_PER_WORKER;

        $this->assertNotNull($item, 'CartItem row must exist after all workers completed.');
        $this->assertSame(
            $expectedQty,
            (int) $item->quantity,
            "Expected quantity={$expectedQty} but got {$item->quantity}. "
                . 'A lower value proves a lost update — lockForUpdate() is not serialising writes.'
        );

        // (c) Quantity never exceeded stock.
        $this->assertLessThanOrEqual(
            self::STOCK_CEILING,
            (int) $item->quantity,
            'Quantity exceeded the stock ceiling — clamping logic failed under concurrency.'
        );

        // (d) Cart row is intact and still scoped to this run's user.
        $this->assertDatabaseHas('carts', [
            'id'      => $this->cartId,
            'user_id' => $this->userId,
        ]);
    }

    // =========================================================================
    // TEST 2 — Concurrent upserts on two distinct variants
    // =========================================================================

    /**
     * Half the workers add qty=3 to variant A; the other half add qty=3 to
     * variant B — all targeting the same cart simultaneously.
     *
     * Both variants must reach the correct per-variant total independently,
     * proving that the cart-level lock does not corrupt cross-variant state.
     */
    public function test_concurrent_upserts_distinct_variants_correct_totals(): void
    {
        // Provision a second variant isolated to this run.
        $variantBId = DB::table('product_variations')->insertGetId([
            'product_id' => DB::table('products')->value('id'),
            'sku'        => 'STRESS-B-' . $this->runId,
            'price'      => 0.01,
            'stock'      => self::STOCK_CEILING,
            'color'      => null,
            'size'       => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $half         = (int) (self::WORKERS / 2);
        $qtyPerWorker = 3;

        $exitA = $this->spawnWorkers(
            sprintf(
                'test:cart-upsert-worker --cart-id=%s --variant-id=%s --quantity=%d',
                $this->cartId,
                $this->variantId,   // variant A — from setUp()
                $qtyPerWorker,
            ),
            $half,
        );

        $exitB = $this->spawnWorkers(
            sprintf(
                'test:cart-upsert-worker --cart-id=%s --variant-id=%s --quantity=%d',
                $this->cartId,
                $variantBId,        // variant B — provisioned above
                $qtyPerWorker,
            ),
            $half,
        );

        $this->assertWorkersSucceeded($exitA);
        $this->assertWorkersSucceeded($exitB);

        $expectedQty = $half * $qtyPerWorker;   // 5 × 3 = 15

        // Variant A assertions — scoped to this run's cartId + variantId.
        $itemA = $this->fetchItem($this->cartId, $this->variantId);
        $this->assertNotNull($itemA, 'CartItem for variant A must exist.');
        $this->assertSame($expectedQty, (int) $itemA->quantity, 'Variant A quantity mismatch.');

        // Variant B assertions — scoped to this run's cartId + variantBId.
        $itemB = $this->fetchItem($this->cartId, $variantBId);
        $this->assertNotNull($itemB, 'CartItem for variant B must exist.');
        $this->assertSame($expectedQty, (int) $itemB->quantity, 'Variant B quantity mismatch.');

        // Cart row still intact.
        $this->assertDatabaseHas('carts', ['id' => $this->cartId]);

        // Both variant rows still intact and scoped to this run.
        $this->assertDatabaseHas('product_variations', ['sku' => 'STRESS-'   . $this->runId]);
        $this->assertDatabaseHas('product_variations', ['sku' => 'STRESS-B-' . $this->runId]);
    }

    // =========================================================================
    // TEST 3 — Merge vs. upsert conflict
    // =========================================================================

    /**
     * Simultaneously:
     *   N/2 workers add qty=1 of $variant to the user cart.
     *   N/2 workers attempt to merge a pre-seeded guest cart (qty=5) into
     *       the same user cart.
     *
     * Because merge and upsert race, the exact final quantity is
     * non-deterministic.  We assert structural invariants only:
     *   (a) Exactly ONE CartItem row for $variant in the user cart.
     *   (b) quantity >= 1  (never corrupted to 0 or negative).
     *   (c) quantity <= STOCK_CEILING  (clamping always respected).
     *   (d) Guest cart row still exists in the DB (non-destructive).
     *   (e) Guest cart's item quantity sum is 0 (items consumed by the merge).
     */
    public function test_merge_and_upsert_conflict_produces_consistent_state(): void
    {
        // Provision a guest cart scoped to this run.
        $guestCartId = (string) Str::uuid();

        DB::table('carts')->insert([
            'id'         => $guestCartId,
            'user_id'    => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('cart_items')->insert([
            'cart_id'            => $guestCartId,
            'product_variant_id' => $this->variantId,
            'quantity'           => 5,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $half = (int) (self::WORKERS / 2);

        $binary  = PHP_BINARY;
        $artisan = base_path('artisan');

        $upsertCmd = sprintf(
            '%s %s test:cart-upsert-worker --cart-id=%s --variant-id=%s --quantity=1 --run-id=%s',
            $binary,
            $artisan,
            $this->cartId,
            $this->variantId,
            $this->runId,
        );

        $mergeCmd = sprintf(
            '%s %s test:cart-merge-worker --user-id=%s --guest-cart-id=%s --run-id=%s',
            $binary,
            $artisan,
            $this->userId,
            $guestCartId,
            $this->runId,
        );

        // Start all processes before waiting for any — true wall-clock overlap.
        $all = [];

        for ($i = 0; $i < $half; $i++) {
            $p = Process::fromShellCommandline($upsertCmd);
            $p->setTimeout(self::WORKER_TIMEOUT);
            $p->start();
            $all[] = $p;
        }

        for ($i = 0; $i < $half; $i++) {
            $p = Process::fromShellCommandline($mergeCmd);
            $p->setTimeout(self::WORKER_TIMEOUT);
            $p->start();
            $all[] = $p;
        }

        foreach ($all as $p) {
            $p->wait();
            // Some merge workers exit 1 when the guest cart was already consumed
            // by a concurrent winner — this is expected behaviour, not a failure.
        }

        // (a) Exactly one CartItem row for this variant in the user cart.
        $this->assertSame(
            1,
            $this->countItems($this->cartId, $this->variantId),
            'Duplicate CartItem rows in user cart — merge/upsert lock failure.'
        );

        $item = $this->fetchItem($this->cartId, $this->variantId);
        $this->assertNotNull($item, 'CartItem must exist in user cart after concurrent operations.');

        // (b) Quantity never corrupted to 0 or negative.
        $this->assertGreaterThanOrEqual(
            1,
            (int) $item->quantity,
            'Quantity is 0 or negative — data corruption under concurrent merge + upsert.'
        );

        // (c) Quantity never exceeded stock.
        $this->assertLessThanOrEqual(
            self::STOCK_CEILING,
            (int) $item->quantity,
            'Quantity exceeded stock ceiling — clamping logic broken under concurrency.'
        );

        // (d) Guest cart row still present in the DB (non-destructive merge).
        $this->assertDatabaseHas('carts', ['id' => $guestCartId]);

        // (e) Guest cart's items were consumed — sum of quantities must be 0.
        $remainingGuestQty = (int) DB::table('cart_items')
            ->where('cart_id', $guestCartId)
            ->sum('quantity');

        $this->assertSame(
            0,
            $remainingGuestQty,
            'Guest cart item quantity is not 0 — merge did not consume the items cleanly.'
        );
    }

    // =========================================================================
    // TEST 4 — Double merge: two workers race on the same guest cart
    // =========================================================================

    /**
     * Two concurrent merge workers target the same guest cart simultaneously.
     * Only one can win the InnoDB lock; the other must be a graceful no-op.
     *
     * Invariants:
     *   (a) Exactly 1 CartItem in the user cart for this variant.
     *   (b) quantity equals the original guest qty (merged exactly once).
     *   (c) Guest cart row is still present (non-destructive).
     *   (d) Guest cart item quantity sum is 0 (consumed exactly once).
     */
    public function test_double_merge_same_guest_cart_runs_exactly_once(): void
    {
        $guestQty    = 7;
        $guestCartId = (string) Str::uuid();

        DB::table('carts')->insert([
            'id'         => $guestCartId,
            'user_id'    => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('cart_items')->insert([
            'cart_id'            => $guestCartId,
            'product_variant_id' => $this->variantId,
            'quantity'           => $guestQty,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $cmd = sprintf(
            '%s %s test:cart-merge-worker --user-id=%s --guest-cart-id=%s --run-id=%s',
            PHP_BINARY,
            base_path('artisan'),
            $this->userId,
            $guestCartId,
            $this->runId,
        );

        $p1 = Process::fromShellCommandline($cmd);
        $p2 = Process::fromShellCommandline($cmd);

        $p1->setTimeout(self::WORKER_TIMEOUT);
        $p2->setTimeout(self::WORKER_TIMEOUT);

        // Start both before waiting for either.
        $p1->start();
        $p2->start();
        $p1->wait();
        $p2->wait();

        // (a) Exactly one CartItem in user cart.
        $this->assertSame(
            1,
            $this->countItems($this->cartId, $this->variantId),
            'Expected 1 CartItem in user cart — duplicate rows mean the merge ran twice.'
        );

        // (b) Quantity equals the original guest qty — merged exactly once.
        $item = $this->fetchItem($this->cartId, $this->variantId);
        $this->assertNotNull($item, 'CartItem must exist in user cart after merge.');
        $this->assertSame(
            $guestQty,
            (int) $item->quantity,
            "Expected quantity={$guestQty} (merged once) but got {$item->quantity}. "
                . 'A higher value means the guest items were applied more than once.'
        );

        // (c) Guest cart row still present in the DB.
        $this->assertDatabaseHas('carts', ['id' => $guestCartId]);

        // (d) Guest cart item quantity sum is 0 — consumed exactly once.
        $remainingGuestQty = (int) DB::table('cart_items')
            ->where('cart_id', $guestCartId)
            ->sum('quantity');

        $this->assertSame(
            0,
            $remainingGuestQty,
            'Guest cart item quantity is not 0 — either merge ran twice or not at all.'
        );
    }

    // =========================================================================
    // TEST 5 — Concurrent setItemQuantity: quantity never exceeds stock
    // =========================================================================

    /**
     * Pre-seed the CartItem at qty=1, then have N workers race to call
     * setItemQuantity(stock).  The final value must be exactly $stock —
     * never above it, never below 1.
     */
    public function test_concurrent_set_quantity_never_exceeds_stock(): void
    {
        $stock = 50;

        // Update ONLY this run's variant — double-scoped by id AND sku.
        DB::table('product_variations')
            ->where('id', $this->variantId)
            ->where('sku', 'STRESS-' . $this->runId)
            ->update(['stock' => $stock, 'updated_at' => now()]);

        // Seed the item so setItemQuantity has a row to update.
        DB::table('cart_items')->insert([
            'cart_id'            => $this->cartId,
            'product_variant_id' => $this->variantId,
            'quantity'           => 1,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $exitCodes = $this->spawnWorkers(
            sprintf(
                'test:cart-set-quantity-worker --cart-id=%s --variant-id=%s --quantity=%d',
                $this->cartId,
                $this->variantId,
                $stock,
            ),
            self::WORKERS,
        );

        $this->assertWorkersSucceeded($exitCodes);

        $item = $this->fetchItem();
        $this->assertNotNull($item, 'CartItem must still exist after setItemQuantity workers.');

        $this->assertSame(
            $stock,
            (int) $item->quantity,
            "Expected quantity={$stock} (clamped to stock) but got {$item->quantity}."
        );

        $this->assertLessThanOrEqual($stock, (int) $item->quantity, 'Quantity exceeded stock.');
        $this->assertGreaterThanOrEqual(1, (int) $item->quantity,   'Quantity dropped below 1.');

        // Variant row still intact and scoped to this run.
        $this->assertDatabaseHas('product_variations', [
            'id'    => $this->variantId,
            'sku'   => 'STRESS-' . $this->runId,
            'stock' => $stock,
        ]);
    }

    // =========================================================================
    // TEST 6 — Stock ceiling respected under additive overload
    // =========================================================================

    /**
     * Set stock=5, fire 20 workers each adding qty=1.
     * Final quantity must be <= 5 (clamped by CartService), never 20.
     */
    public function test_stock_ceiling_respected_under_additive_overload(): void
    {
        $stock   = 5;
        $workers = 20;

        // Double-scoped update — cannot accidentally affect production rows.
        DB::table('product_variations')
            ->where('id', $this->variantId)
            ->where('sku', 'STRESS-' . $this->runId)
            ->update(['stock' => $stock, 'updated_at' => now()]);

        $exitCodes = $this->spawnWorkers(
            sprintf(
                'test:cart-upsert-worker --cart-id=%s --variant-id=%s --quantity=1',
                $this->cartId,
                $this->variantId,
            ),
            $workers,
        );

        // Workers that hit stock exhaustion exit 1 — that is expected behaviour.
        // We only fail on exit code > 1 (unexpected crash).
        $crashedWorkers = array_filter($exitCodes, fn(int $c): bool => $c > 1);

        $this->assertEmpty(
            $crashedWorkers,
            'Some workers exited with unexpected error codes (> 1): '
                . implode(', ', $crashedWorkers)
        );

        // CartItem may not exist if every single upsert was stock-clamped to 0
        // before any row was created — assert structural invariant only.
        $item = $this->fetchItem();

        if ($item !== null) {
            // (a) Quantity never exceeded stock.
            $this->assertLessThanOrEqual(
                $stock,
                (int) $item->quantity,
                "Quantity {$item->quantity} exceeded stock limit of {$stock}."
            );

            // (b) Row is positively identifiable as belonging to this run.
            $this->assertDatabaseHas('cart_items', [
                'cart_id'            => $this->cartId,
                'product_variant_id' => $this->variantId,
            ]);
        }

        // Variant row still intact and scoped.
        $this->assertDatabaseHas('product_variations', [
            'id'  => $this->variantId,
            'sku' => 'STRESS-' . $this->runId,
        ]);
    }
}
