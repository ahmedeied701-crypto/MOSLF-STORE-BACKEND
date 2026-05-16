<?php

// =============================================================================
// app/Repositories/Cart/CartRepository.php
// =============================================================================

namespace App\Repositories\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ProductVariation;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use App\Services\Cart\Contracts\CartRepositoryInterface;

class CartRepository implements CartRepositoryInterface
{
    // =========================================================================
    // CART RESOLUTION
    // =========================================================================

    /**
     * Resolve or create a cart with a write lock.
     * MUST be called inside DB::transaction().
     * Lock order position: FIRST (before variants, before items).
     */
    public function resolveCartLocked(Request $request): Cart
    {
        return $request->user()
            ? $this->resolveUserCartLocked((int) $request->user()->id)
            : $this->resolveGuestCartLocked(
                (string) (
                    $request->header('X-Guest-Cart-ID')
                    ?? $request->cookie('guest_cart_id')
                )
            );
    }

    public function resolveUserCartLocked(int $userId): Cart
    {
        $cart = Cart::where('user_id', $userId)->lockForUpdate()->first();

        if (!$cart) {
            Cart::insertOrIgnore([
                'id' => (string) Str::uuid(),
                'user_id' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $cart = Cart::where('user_id', $userId)->lockForUpdate()->firstOrFail();
        }

        return $cart;
    }

    public function resolveGuestCartLocked(string $guestId): Cart
    {
        if (!$guestId || !Str::isUuid($guestId)) {
            $guestId = (string) Str::uuid();
        }

        $cart = Cart::where('id', $guestId)->lockForUpdate()->first();

        if (!$cart) {
            Cart::insertOrIgnore(['id' => $guestId]);
            $cart = Cart::where('id', $guestId)->lockForUpdate()->firstOrFail();
        }

        return $cart;
    }

    public function resolveCart(Request $request): ?Cart
    {
        return $request->user()
            ? $this->findUserCart((int) $request->user()->id)
            : $this->findGuestCart(
                (string) (
                    $request->header('X-Guest-Cart-ID')
                    ?? $request->cookie('guest_cart_id')
                )
            );
    }

    public function resolveCartForRead(Request $request): Cart
    {
        return $request->user()
            ? $this->resolveUserCartLocked((int) $request->user()->id)
            : $this->resolveGuestCartLocked(
                (string) ($request->header('X-Guest-Cart-ID') ?? '')
            );
    }


    private function findUserCart(int $userId): ?Cart
    {
        return Cart::where('user_id', $userId)->first();
    }

    private function findGuestCart(string $guestId): ?Cart
    {
        if (!$guestId || !Str::isUuid($guestId)) {
            return null;
        }
        return Cart::where('id', $guestId)->first();
    }

    /**
     * Find (not lock) a user cart — used for ID resolution before locking.
     */
    public function findOrCreateUserCart(int $userId): Cart
    {
        $cart = Cart::where('user_id', $userId)->first();

        if (!$cart) {
            Cart::insertOrIgnore([
                'id' => (string) Str::uuid(),
                'user_id' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $cart = Cart::where('user_id', $userId)->firstOrFail();
        }

        return $cart;
    }

    // =========================================================================
    // BATCH LOCKING PRIMITIVES
    // =========================================================================

    /**
     * Batch-lock ProductVariation rows in primary-key ascending order.
     * Consistent order prevents deadlocks across concurrent requests.
     * Lock order position: SECOND (after cart, before items).
     *
     * @param  string[] $variantIds
     * @return Collection<string, ProductVariation>
     */
    public function lockVariants(array $variantIds): Collection
    {
        if (empty($variantIds)) {
            return collect();
        }

        $sorted = collect($variantIds)->sort()->values()->all();

        return ProductVariation::whereIn('id', $sorted)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /**
     * Batch-lock CartItem rows for a cart, scoped to variant IDs.
     * Lock order position: THIRD (after cart and variants).
     *
     * @param  string[] $variantIds  Empty array locks all items on the cart.
     * @return Collection<string, CartItem>
     */
    public function lockCartItems(string $cartId, array $variantIds = []): Collection
    {
        $query = CartItem::where('cart_id', $cartId)
            ->orderBy('product_variant_id')
            ->lockForUpdate();

        if (!empty($variantIds)) {
            $sorted = collect($variantIds)->sort()->values()->all();
            $query->whereIn('product_variant_id', $sorted);
        }

        return $query->get()->keyBy('product_variant_id');
    }

    /**
     * Batch-lock all CartItem rows on a cart (no variant filter).
     *
     * @return Collection<string, CartItem>
     */
    public function lockAllCartItems(string $cartId): Collection
    {
        return $this->lockCartItems($cartId, []);
    }

    // =========================================================================
    // ITEM WRITES
    // =========================================================================

    public function createItem(string $cartId, string $variantId, int $quantity): CartItem
    {
        return CartItem::updateOrCreate(
            [
                'cart_id' => $cartId,
                'product_variant_id' => $variantId,
            ],
            [
                'quantity' => $quantity,
            ]
        );
    }

    public function updateItemQuantity(CartItem $item, int $quantity): void
    {
        $item->update(['quantity' => $quantity]);
    }

    public function deleteItem(CartItem $item): void
    {
        $item->delete();
    }

    public function deleteItemByVariant(string $cartId, string $variantId): void
    {
        CartItem::where('cart_id', $cartId)
            ->where('product_variant_id', $variantId)
            ->lockForUpdate()
            ->delete();
    }

    /**
     * Delete all CartItems for a cart that are NOT in the given variant set.
     * Used during persistCart to remove stale items.
     *
     * @param string[] $keepVariantIds
     */
    public function deleteItemsExcept(string $cartId, array $keepVariantIds): void
    {
        $query = CartItem::where('cart_id', $cartId)->lockForUpdate();

        if (!empty($keepVariantIds)) {
            $query->whereNotIn('product_variant_id', $keepVariantIds);
        }

        $query->delete();
    }

    public function deleteAllItems(string $cartId): void
    {
        CartItem::where('cart_id', $cartId)->delete();
    }

    /**
     * Bulk insert new CartItem rows in a single query.
     *
     * @param array<int, array{cart_id: string, product_variant_id: string, quantity: int, created_at: \Illuminate\Support\Carbon, updated_at: \Illuminate\Support\Carbon}> $rows
     */
    public function bulkInsertItems(array $rows): void
    {
        if (!empty($rows)) {
            CartItem::insert($rows);
        }
    }

    // =========================================================================
    // CART TEARDOWN
    // =========================================================================

    public function deleteCart(Cart $cart): void
    {
        $cart->delete();
    }

    // =========================================================================
    // RESPONSE HYDRATION
    // =========================================================================

    public function loadCartForResponse(Cart $cart): Cart
    {
        return $cart->load(['items.productVariant.product', 'items.productVariant.images']);
    }

    /**
     * Lock both carts in deterministic UUID-ascending order to prevent
     * deadlocks between concurrent merge calls on the same cart pair.
     *
     * @return array{userCart: Cart, guestCart: Cart|null}
     */
    public function lockBothCartsOrdered(string $userCartId, string $guestCartId): array
    {
        [$firstId, $secondId] = strcmp($userCartId, $guestCartId) <= 0
            ? [$userCartId, $guestCartId]
            : [$guestCartId, $userCartId];

        $first  = Cart::where('id', $firstId)->lockForUpdate()->first();
        $second = Cart::where('id', $secondId)->lockForUpdate()->first();

        $userCart  = ($firstId === $userCartId)  ? $first  : $second;
        $guestCart = ($firstId === $guestCartId) ? $first  : $second;

        return compact('userCart', 'guestCart');
    }

    // =========================================================================
    // ITEM LOOKUPS (READ / NON-LOCK HELPERS)
    // =========================================================================

    public function findCartItem(string $cartId, string $variantId): ?CartItem
    {
        return CartItem::where('cart_id', $cartId)
            ->where('product_variant_id', $variantId)
            ->first();
    }

    public function findCartItems(string $cartId, array $variantIds): Collection
    {
        return CartItem::where('cart_id', $cartId)
            ->whereIn('product_variant_id', $variantIds)
            ->get()
            ->keyBy('product_variant_id');
    }
}
