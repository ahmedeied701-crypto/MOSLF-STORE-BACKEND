<?php

declare(strict_types=1);

// =============================================================================
// app/Services/Cart/CartService.php
// =============================================================================

namespace App\Services\Cart;

use App\Models\Cart;
use App\Domain\Cart\Services\CartDomainService;
use App\Services\Cart\Contracts\CartRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
// use Illuminate\Support\Facades\Cache;

/**
 * Orchestration layer for the Cart bounded context.
 *
 * Responsibilities
 * ────────────────
 *  • Own every DB::transaction() boundary.
 *  • Coordinate CartRepository (data) and CartDomainService (rules).
 *  • Return a consistent array shape for the HTTP layer.
 *
 * Hard constraints
 * ────────────────
 *  • No raw Eloquent queries — all DB access through CartRepositoryInterface.
 *  • No business-rule logic — all decisions through CartDomainService.
 *  • No dependency on DB facade beyond wrapping transactions.
 *
 * Transaction design
 * ──────────────────
 *  Every DB::transaction() block:
 *    1. Returns a minimally hydrated value (model ID or scalar) — never a
 *       full response array.  Response building always happens outside the
 *       lock window.
 *    2. Accepts a retry count of 3 to automatically recover from transient
 *       deadlocks without surfacing errors to the caller.
 *    3. Keeps the lock window as short as possible: eager-loading and
 *       response transformation happen after the transaction commits.
 *
 * Lock acquisition order (never deviate — prevents circular waits)
 * ────────────────────────────────────────────────────────────────
 *   1. Cart              (lockForUpdate via resolveCartLocked / lockBothCartsOrdered)
 *   2. ProductVariation  (lockForUpdate via lockVariants — ascending PK)
 *   3. CartItem          (lockForUpdate via lockCartItems — ascending variant PK)
 */
final class CartService
{
    /**
     * Number of automatic retry attempts on any write transaction.
     *
     * InnoDB raises a deadlock error (SQLSTATE 40001) when two transactions
     * form a circular lock-wait.  Laravel's DB::transaction() detects this
     * and replays the closure up to $attempts - 1 additional times before
     * re-throwing.  Three attempts covers virtually all transient deadlocks
     * without masking genuine data-integrity errors.
     */
    private const TRANSACTION_ATTEMPTS = 3;

    public function __construct(
        private readonly CartRepositoryInterface $repo,
        private readonly CartDomainService       $domain,
    ) {}
    // =========================================================================
// IDEMPOTENCY INFRASTRUCTURE
// =========================================================================

    /**
     * Deterministic, collision-safe cache key.
     *
     * Scoped to: owner (user ID or guest cart UUID) + operation + payload hash.
     * SHA-256 over the payload prevents key length blowup and collisions between
     * e.g. {variantId: "abc", qty: 1} and {variantId: "ab", qty: 21}.
     */
    // private function idempKey(Request $request, string $operation, array $payload = []): string
    // {

    //     $guestId = $request->header('X-Guest-Cart-ID') ?? $request->cookie('guest_cart_id') ?? 'anon';

    //     // $owner = $request->user()?->id
    //     //     ? 'u:' . $request->user()->id
    //     //     : 'g:' . ($request->cookie('cart_key') ?? 'anon');
    //     $owner = $request->user()?->id
    //         ? 'u:' . $request->user()->id
    //         : 'g:' . $guestId;

    //     $payloadHash = hash('sha256', $operation . ':' . json_encode($payload, JSON_THROW_ON_ERROR));

    //     return "cart_idem:{$owner}:{$payloadHash}";
    // }

    /**
     * Atomic idempotency gate — lock → double-check → execute → cache → release.
     *
     * GUARANTEES:
     *  • Exactly-once DB execution per (owner + operation + payload).
     *  • Cache written ONLY after $work() returns successfully.
     *  • Errors / exceptions are never cached.
     *  • Redis outage degrades gracefully: falls through to direct execution.
     *
     * @param  string   $key   From idempKey().
     * @param  callable $work  Must return final response array. Must be idempotent at DB level too.
     */
    // private function idempotent(string $key, callable $work): array
    // {
    //     // ── Fast path: cache hit, no lock needed ──────────────────────────────
    //     try {
    //         $cached = Cache::get($key);
    //         if ($cached !== null) {
    //             return $cached;
    //         }
    //     } catch (\Throwable) {
    //         // Redis unavailable — skip idempotency, fall through to direct execution.
    //         return $work();
    //     }

    //     // ── Slow path: acquire lock, double-check, execute ────────────────────
    //     $lock = Cache::lock('idem_lock:' . $key, 30);

    //     try {
    //         // block(5): waits up to 5s. Throws LockTimeoutException on timeout.
    //         // Under Redis failure this also throws — caught below.
    //         $lock->block(5);

    //         // Double-check after acquiring lock (another process may have just written).
    //         $cached = Cache::get($key);
    //         if ($cached !== null) {
    //             return $cached;
    //         }

    //         $response = $work();

    //         // Only cache a clean, complete response — never partial/error state.
    //         // Cache::put($key, $response, now()->addHours(24));

    //         return $response;
    //     } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
    //         // Could not acquire lock within 5s (Redis slow or contention spike).
    //         // Degrade: execute without idempotency guarantee rather than fail the request.
    //         Log::warning('Cart idempotency lock timeout — degrading to direct execution', [
    //             'key' => $key,
    //         ]);
    //         return $work();
    //     } catch (\Throwable $e) {
    //         // Redis down mid-flight — degrade gracefully, never swallow DB exceptions.
    //         if ($e instanceof \Illuminate\Validation\ValidationException) {
    //             throw $e;
    //         }
    //         Log::error('Cart idempotency cache failure', ['key' => $key, 'error' => $e->getMessage()]);
    //         return $work();
    //     } finally {
    //         // forceRelease() is safe even if the lock was never acquired.
    //         try {
    //             $lock->forceRelease();
    //         } catch (\Throwable) {
    //         }
    //     }
    // }
    

    // =========================================================================
    // READ
    // =========================================================================

    /**
     * Return the current cart for the request owner (read-only).
     *
     * Why no transaction here
     * ───────────────────────
     * This is a pure display path — no rows are created, updated, or deleted.
     * Wrapping it in DB::transaction() would:
     *   a) acquire unnecessary shared-intent locks on InnoDB,
     *   b) hold a connection open for the full eager-load duration,
     *   c) block behind any concurrent write transaction on the same cart.
     *
     * InnoDB's MVCC guarantees a consistent snapshot on plain SELECTs, so the
     * read is safe without an explicit transaction boundary.
     *
     * Null / empty-cart handling
     * ──────────────────────────
     * resolveCart() returns null when the cart does not exist yet (first visit,
     * no items ever added).  We return an empty-cart shape rather than creating
     * a row — a cart row is only created on the first mutation (upsertItem etc).
     */
    // public function getCartForRequest(Request $request): array
    // {
    //     $cart = $this->repo->resolveCart($request);

    //     // ── Bootstrap guest cart if missing ─────────────
    //     if ($cart === null) {
    //         $guestId = $request->header('X-Guest-Cart-ID');

    //         if ($guestId && Str::isUuid($guestId)) {
    //             $cart = $this->repo->resolveGuestCartLocked($guestId);
    //         } else {
    //             $guestId = (string) Str::uuid();

    //             return [
    //                 'cartKey' => $guestId,
    //                 'items' => [],
    //             ];
    //         }
    //     }


    //     // loadCartForResponse() issues the eager-load entirely outside any
    //     // transaction — it must never be called while locks are held.
    //     return $this->transformCartModel(
    //         $this->repo->loadCartForResponse($cart)
    //     );
    // }
    public function getCartForRequest(Request $request): array
    {
        $cart = $this->repo->resolveCart($request);

        // ── Bootstrap guest cart if missing ─────────────
        if ($cart === null) {

            $guestId = $request->header('X-Guest-Cart-ID')
                ?? $request->cookie('guest_cart_id');

            if ($guestId && Str::isUuid($guestId)) {
                $cart = $this->repo->resolveGuestCartLocked($guestId);
            } else {
                // IMPORTANT: return empty cart, DO NOT create yet
                return [
                    'cartKey' => null,
                    'items' => [],
                    'action' => 'create_on_write',
                ];
                // $cart = $this->repo->resolveGuestCartLocked((string) Str::uuid());
            }
        }

        $loaded = $this->repo->loadCartForResponse($cart);

        return $this->transformCartModel($loaded);
    }

    /**
     * Canonical empty-cart shape returned before any item has been added.
     *
     * Keeping it here (rather than in the controller) means every layer that
     * calls getCartForRequest() receives a consistent, typed structure even
     * when no DB row exists yet.
     *
     * @return array{cartKey: null, items: array<never>}
     */
    private function emptyCartShape(): array
    {
        return [
            'cartKey' => null,
            'items'   => [],
        ];
    }

    // =========================================================================
    // UPSERT ITEM
    // =========================================================================

    /**
     * Additively increase a variant's quantity in the cart.
     * Quantity < 1 is forwarded to removeItem.
     *
     * Transaction scope
     * ─────────────────
     * The closure acquires locks, applies the domain write, and returns only
     * the Cart primary key.  Response building (fresh() + eager-load) happens
     * after the transaction commits so the lock window stays minimal.
     *
     * @throws ValidationException  When variant is invalid or out of stock.
     */
    public function upsertItem(Request $request, string $variantId, int $quantity): array
    {
        Log::info('UPSERT HIT', [
            'variant' => $variantId,
            'qty' => $quantity,
            'cart' => $request->header('X-Guest-Cart-ID'),
            'user' => $request->user()?->id,
            'ip' => $request->ip(),
            'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3),
        ]);

        if ($quantity < 1) {
            return $this->removeItem($request, $variantId);
        }

        // ── CPU-only payload normalisation — outside transaction ───────────────
        // $key = $this->idempKey($request, 'upsert', [
        //     'variantId' => $variantId,
        //     'quantity'  => $quantity,
        // ]);
        // $key = $this->idempKey($request, 'upsert', [
        //     'variantId' => $variantId,
        // ]);

        // return $this->idempotent($key, function () use ($request, $variantId, $quantity): array {
        return (function () use ($request, $variantId, $quantity): array {


            // ── Write transaction: returns Cart ID only ────────────────────────
            $result = DB::transaction(function () use ($request, $variantId, $quantity): array {
                Log::info('TRANSACTION START', [
                    'variant' => $variantId,
                ]);

                // Lock order: Cart → Variant (no CartItem lock — see below)
                $cart    = $this->repo->resolveCartLocked($request);
                $variants = $this->repo->lockVariants([$variantId]);
                $variant  = $variants->get($variantId);

                if ($variant === null) {
                    throw new ValidationException(
                        validator([], []),
                        response()->json(['error' => 'Invalid product variant.'], 400)
                    );
                }

                // ── CartItem: plain SELECT, no lockForUpdate ───────────────────
                // Rationale: the Cart row lock (LOCK IN SHARE MODE / FOR UPDATE)
                // already serialises all writers on this cart. A second FOR UPDATE
                // on CartItem would extend the lock chain to a third table,
                // increasing deadlock surface with no safety gain — the domain
                // write is already protected by the Cart lock.
                $existing = $this->repo->findCartItem((string) $cart->id, $variantId);

                $applied = $this->domain->applyUpsert($cart, $variant, $existing, $quantity);

                if ($applied === null) {
                    // throw new ValidationException(
                    //     validator([], []),
                    //     response()->json(['error' => 'Product variant is out of stock.'], 422)
                    // );
                    return [
                        'cartId' => (string) $cart->id,
                        'action' => 'removed_oos',
                        'reason' => 'out_of_stock',
                    ];
                }
                return [
                    'cartId' => (string) $cart->id,
                ];
            }, self::TRANSACTION_ATTEMPTS);

            // ── Post-transaction hydration ───────────────────────────────────────
            $response = $this->respond($result['cartId']);

            // attach domain signal if exists
            if (!empty($result['action'])) {
                $response['action'] = $result['action'];
                $response['reason'] = $result['reason'] ?? null;
            }

            return $response;
        })();
    }

    // =========================================================================
    // SET ITEM QUANTITY
    // =========================================================================

    /**
     * Override a cart item's quantity to an exact value.
     *
     * Hybrid strategy
     * ───────────────
     *  • Backend DB state is always the authoritative base — never the value
     *    the client thinks is current.
     *  • $expectedCurrentQty is advisory only: when supplied and it disagrees
     *    with the DB we log a desync warning for observability, then continue
     *    normally.  The request is NEVER rejected because of a mismatch.
     *
     * Flow
     * ────
     *  1. Lock cart  (Cart → Variant → CartItem — order unchanged)
     *  2. Read current DB quantity
     *  3. Soft-check expected vs actual  → warn-only on mismatch
     *  4. Clamp requested quantity against live stock via domain
     *  5. quantity <= 0 after clamp → delegate to removeItem
     *     otherwise                → apply update through domain
     *
     * Transaction scope
     * ─────────────────
     * The closure returns a small result envelope (cart ID + optional metadata)
     * rather than a full response array.  Eager-loading happens after commit.
     *
     * @param int|null $expectedCurrentQty  Client-reported current quantity.
     *                                      Null means the client did not send it.
     */
    public function setItemQuantity(
        Request $request,
        string  $variantId,
        int     $quantity,
        ?int    $expectedCurrentQty = null,
    ): array {
        if ($quantity <= 0) {
            return $this->removeItem($request, $variantId);
        }

        // $key = $this->idempKey($request, 'setqty', [
        //     'variantId' => $variantId,
        //     'quantity'  => $quantity,
        // ]);

        // return $this->idempotent($key, function () use (
        //     $request,
        //     $variantId,
        //     $quantity,
        //     $expectedCurrentQty,
        // ): array {

        $result = DB::transaction(function () use (
            $request,
            $variantId,
            $quantity,
            $expectedCurrentQty,
        ): array {

            // Lock order: Cart → Variant
            $cart     = $this->repo->resolveCartLocked($request);
            $variants = $this->repo->lockVariants([$variantId]);

            // Plain SELECT — Cart lock serialises concurrent writers on this cart.
            $item       = $this->repo->findCartItem((string) $cart->id, $variantId);
            $currentQty = $item?->quantity ?? 0;

            // Soft desync check — observability only, never blocks the request.
            if ($expectedCurrentQty !== null && $expectedCurrentQty !== $currentQty) {
                Log::warning('Cart quantity desync detected', [
                    'variant_id' => $variantId,
                    'expected'   => $expectedCurrentQty,
                    'actual'     => $currentQty,
                    'requested'  => $quantity,
                    'user_id'    => $request->user()?->id,
                    'cart_id'    => (string) $cart->id,
                    'ip'         => $request->ip(),
                ]);
            }

            if ($item === null) {
                return [
                    'cartId' => (string) $cart->id,
                    'action' => 'noop',
                    'reason' => 'item_not_found',
                ];
            }

            $this->domain->applySetQuantity(
                $variants->get($variantId),
                $item,
                $quantity,
            );

            return ['cartId' => (string) $cart->id];
        }, self::TRANSACTION_ATTEMPTS);

        $response = $this->respond($result['cartId']);

        if (isset($result['action'])) {
            $response['action'] = $result['action'];
            $response['reason'] = $result['reason'];
        }

        return $response;
    }

    // =========================================================================
    // REMOVE ITEM
    // =========================================================================

    /**
     * Transaction scope
     * ─────────────────
     * Returns Cart ID only; response building happens after commit.
     * Lock order: Cart → CartItem (no variant needed for deletion).
     */
    public function removeItem(Request $request, string $variantId): array
    {
        // $key = $this->idempKey($request, 'remove', ['variantId' => $variantId]);

        // return $this->idempotent($key, function () use ($request, $variantId): array {

        $cartId = DB::transaction(function () use ($request, $variantId): string {

            // Lock order: Cart only.
            // No Variant lock needed — DELETE does not touch stock.
            // No CartItem lock needed — Cart FOR UPDATE covers the write.
            $cart = $this->repo->resolveCartLocked($request);
            $this->repo->deleteItemByVariant((string) $cart->id, $variantId);

            return (string) $cart->id;
        }, self::TRANSACTION_ATTEMPTS);

        return $this->respond($cartId);
    }

    // =========================================================================
    // MERGE GUEST CART
    // =========================================================================

    /**
     * Merge a guest cart into the authenticated user's cart.
     *
     * Returns an empty array when preconditions fail (unauthenticated, missing
     * or invalid guest_cart_id). The controller must guard against this.
     *
     * Deadlock prevention
     * ───────────────────
     * Both carts are locked in deterministic UUID-ascending order so that two
     * concurrent merges involving the same pair always acquire locks in the
     * same sequence — eliminating circular waits.
     *
     * Transaction flow
     * ────────────────
     *  1. Lock both carts  (ascending UUID order)
     *  2. Batch-lock variants        (ascending PK)
     *  3. Batch-lock user items      (ascending variant PK)
     *  4. Domain computes merge plan in-memory
     *  5. Single write phase         (bulk insert + individual updates)
     *  6. Purge guest cart items atomically
     *
     * Transaction scope
     * ─────────────────
     * Closure returns a result envelope with the user cart ID (and a flag for
     * the empty-guest-cart fast-path).  Eager-loading happens after commit.
     */
    // public function mergeGuestCart(Request $request): array
    // {
    //     $user        = $request->user();
    //     $guestCartId = $request->input('guest_cart_id');

    //     if (!$user || !$guestCartId || !Str::isUuid($guestCartId)) {
    //         return [];
    //     }

    //     // Key includes guestCartId — two concurrent merges of different guest carts
    //     // into the same user must not share a cache entry.
    //     // $key = $this->idempKey($request, 'merge', ['guestCartId' => $guestCartId]);

    //     // return $this->idempotent($key, function () use ($user, $guestCartId): array {

    //     $result = DB::transaction(function () use ($user, $guestCartId): array {

    //         // Resolve user cart ID before locking (needed for UUID ordering).
    //         $userCart = $this->repo->findOrCreateUserCart((int) $user->id);

    //         // Lock order: both carts in ascending UUID order (deadlock prevention).
    //         [
    //             'userCart'  => $userCart,
    //             'guestCart' => $guestCart,
    //         ] = $this->repo->lockBothCartsOrdered(
    //             (string) $userCart->id,
    //             $guestCartId,
    //         );

    //         if ($userCart === null) {
    //             return ['userCartId' => null, 'earlyReturn' => 'no_user_cart'];
    //         }

    //         if ($guestCart === null) {
    //             return [
    //                 'userCartId'  => (string) $userCart->id,
    //                 'earlyReturn' => 'guest_cart_gone',
    //             ];
    //         }

    //         // Guest items DO need lockForUpdate — they will be deleted below.
    //         $guestItems = $this->repo->lockCartItems((string) $guestCart->id);

    //         if ($guestItems->isEmpty()) {
    //             return [
    //                 'userCartId'  => (string) $userCart->id,
    //                 'earlyReturn' => 'guest_cart_empty',
    //             ];
    //         }

    //         $variantIds = $guestItems->keys()->all();

    //         // Lock order: Variant (ascending PK enforced inside repo).
    //         $variants = $this->repo->lockVariants($variantIds);

    //         // User cart items: plain SELECT — user Cart lock serialises writers.
    //         $userItems = $this->repo->findCartItems((string) $userCart->id, $variantIds);

    //         $this->domain->applyMerge($userCart, $guestItems, $variants, $userItems);
    //         $this->repo->deleteAllItems((string) $guestCart->id);

    //         return ['userCartId' => (string) $userCart->id];
    //         Log::info('USER CART AFTER CREATE', [
    //             'user_id' => $user->id,
    //             'cart_id' => $userCart->id,
    //             'cart_user_id' => $userCart->user_id,
    //         ]);
    //     }, self::TRANSACTION_ATTEMPTS);

    //     if (($result['earlyReturn'] ?? null) === 'no_user_cart') {
    //         return [];
    //     }

    //     // guest_cart_gone / guest_cart_empty still return current user cart.
    //     return $this->respond($result['userCartId']);
    // }
    // public function mergeGuestCart(Request $request): array
    // {
    //     $user = $request->user();   
    //     $guestCartId = $request->input('guest_cart_id');

    //     if (!$user || !$guestCartId || !Str::isUuid($guestCartId)) {
    //         return [];
    //     }

    //     $result = DB::transaction(function () use ($user, $guestCartId): array {
    //         $userCart = $this->repo->findOrCreateUserCart((int) $user->id);

    //         [
    //             'userCart'  => $userCart,
    //             'guestCart' => $guestCart,
    //         ] = $this->repo->lockBothCartsOrdered((string) $userCart->id, $guestCartId);

    //         if (!$guestCart) {
    //             return ['userCartId' => (string) $userCart->id];
    //         }

    //         $guestItems = $this->repo->lockCartItems((string) $guestCart->id);

    //         if ($guestItems->isNotEmpty()) {
    //             $variantIds = $guestItems->keys()->all();
    //             $variants = $this->repo->lockVariants($variantIds);

    //             $userItems = $this->repo->findCartItems((string) $userCart->id, $variantIds);

    //             $this->domain->applyMerge($userCart, $guestItems, $variants, $userItems);

    //             $this->repo->deleteAllItems((string) $guestCart->id);
    //         }

    //         DB::table('carts')->where('id', $guestCartId)->delete();

    //         return ['userCartId' => (string) $userCart->id];
    //     }, self::TRANSACTION_ATTEMPTS);

    //     return $this->respond($result['userCartId']);
    // }
    public function mergeGuestCart(Request $request): array
    {
        $user = $request->user();
        $guestCartId = $request->input('guest_cart_id');

        if (!$user || !$guestCartId) return [];

        try {
            $result = DB::transaction(function () use ($user, $guestCartId): array {
                $userCart = $this->repo->findOrCreateUserCart((int) $user->id);

                $guestCart = DB::table('carts')->where('id', $guestCartId)->first();

                if ($guestCart) {
                    $guestItems = $this->repo->lockCartItems($guestCartId);
                    if ($guestItems->isNotEmpty()) {
                        $variantIds = $guestItems->keys()->all();
                        $variants = $this->repo->lockVariants($variantIds);
                        $userItems = $this->repo->findCartItems((string) $userCart->id, $variantIds);

                        $this->domain->applyMerge($userCart, $guestItems, $variants, $userItems);

                        DB::table('cart_items')->where('cart_id', $guestCartId)->delete();
                    }

                    DB::table('carts')->where('id', $guestCartId)->delete();
                }

                return ['userCartId' => (string) $userCart->id];
            });

            return $this->respond($result['userCartId']);
        } catch (\Exception $e) {
            Log::error("Merge Failed: " . $e->getMessage());
            return ['error' => 'Merge failed'];
        }
    }

    // =========================================================================
    // PERSIST CART  (full replace from external payload)
    // =========================================================================

    /**
     * Replace cart contents from an external payload.
     *
     * Transaction flow
     * ────────────────
     *  1. Lock cart
     *  2. Normalize & validate incoming payload
     *  3. Delete stale items         (not in incoming set)
     *  4. Batch-lock variants        (ascending PK)
     *  5. Batch-lock remaining items (ascending variant PK)
     *  6. Domain diffs in-memory
     *  7. Single bulk insert + selective updates
     *
     * Transaction scope
     * ─────────────────
     * Closure returns Cart ID only; response building happens after commit.
     */
    public function persistCart(Request $request, array $cart): array
    {
        // ── Payload normalisation: pure CPU, outside transaction AND outside gate ──
        // Done here so the idempotency key reflects the actual intended state,
        // and the transaction closure receives pre-validated, sorted data.
        $incoming = collect($cart['items'] ?? [])
            ->filter(
                fn($i) => !empty($i['variantId'])
                    && Str::isUuid($i['variantId'])
                    && isset($i['quantity'])
                    && (int) $i['quantity'] >= 1
            )
            ->keyBy('variantId')
            ->map(fn($i) => (int) $i['quantity']);

        $incomingIds = $incoming->keys()->sort()->values()->all();

        // Key is scoped to the exact intended cart state — different payloads
        // on the same route produce different keys correctly.
        // $key = $this->idempKey($request, 'persist', ['ids' => $incomingIds, 'qtys' => $incoming->all()]);

        // return $this->idempotent($key, function () use ($request, $incoming, $incomingIds): array {

        $cartId = DB::transaction(function () use ($request, $incoming, $incomingIds): string {

            // Lock order: Cart → Variant → CartItem
            $cartModel = $this->repo->resolveCartLocked($request);

            // Stale items removed while Cart lock is held.
            $this->repo->deleteItemsExcept((string) $cartModel->id, $incomingIds);

            if ($incoming->isNotEmpty()) {
                $variants = $this->repo->lockVariants($incomingIds);

                // Plain SELECT — Cart lock serialises writers on this cart.
                $existingItems = $this->repo->findCartItems(
                    (string) $cartModel->id,
                    $incomingIds,
                );

                $this->domain->applyPersist(
                    (string) $cartModel->id,
                    $incoming,
                    $variants,
                    $existingItems,
                );
            }

            return (string) $cartModel->id;
        }, self::TRANSACTION_ATTEMPTS);

        return $this->respond($cartId);
    }

    // =========================================================================
    // INTERNAL RESPONSE BUILDER
    // =========================================================================

    /**
     * Load a Cart by ID, eager-hydrate its relations, and transform it into
     * the canonical API response array.
     *
     * This method is deliberately called OUTSIDE every DB::transaction() block
     * so that the heavy relation load (items → productVariant → product →
     * images) never runs while InnoDB row locks are held.  Releasing locks
     * before eager-loading removes a significant source of lock-contention
     * latency under high concurrency.
     *
     * @param  string $cartId  Primary key of the cart to hydrate.
     * @return array           Canonical cart response shape.
     */
    private function respond(string $cartId): array
    {
        // Re-fetch the cart by ID after the transaction committed.
        // This plain SELECT runs outside any lock window.
        $cart = Cart::findOrFail($cartId);

        return $this->transformCartModel($this->repo->loadCartForResponse($cart));
    }

    /**
     * Transform a fully-loaded Cart model into the wire format consumed by
     * every CartController action.
     *
     * This is the single source of truth for the response array structure.
     * Neither the controller nor any service caller should re-shape this data.
     */
    private function transformCartModel(Cart $cart): array
    {
        return [
            'cartKey' => $cart->id,
            'items'   => $cart->items->map(function ($item): array {
                $variant = $item->productVariant;
                $product = $variant?->product;
                $image   = $variant?->images->first();

                return [
                    'variantId'    => (string) ($variant?->id ?? ''),
                    'productId'    => (string) ($product?->id ?? ''),
                    'productName'  => $product?->name,
                    'slug'         => $product?->slug,
                    'price'        => (float) ($variant?->price ?? 0),
                    'quantity'     => $item->quantity,
                    'image'        => $image?->image_url ?? $image?->image_path,
                    'options'      => [
                        'Color' => $variant?->color,
                        'Size'  => $variant?->size,
                    ],
                    'isCustomized' => false,
                    'customData'   => null,
                    'customId'     => null,
                ];
            })->toArray(),
        ];
    }
}
