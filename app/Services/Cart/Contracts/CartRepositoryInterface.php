<?php

declare(strict_types=1);

// =============================================================================
// app/Services/Cart/Contracts/CartRepositoryInterface.php
// =============================================================================

namespace App\Services\Cart\Contracts;

use App\Models\Cart;
use App\Models\CartItem;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

interface CartRepositoryInterface
{
    // ── Resolution ────────────────────────────────────────────────────────────

    /**
     * Resolve the request-owner's cart with NO lock (read-only path).
     * Returns null when the cart does not yet exist — callers must handle this.
     * MUST NOT be called inside a write transaction.
     */
    public function resolveCart(Request $request): ?Cart;

    public function resolveCartForRead(Request $request): Cart;

    public function resolveCartLocked(Request $request): Cart;

    public function resolveUserCartLocked(int $userId): Cart;

    public function resolveGuestCartLocked(string $guestId): Cart;

    public function findOrCreateUserCart(int $userId): Cart;

    // ── Locking primitives ────────────────────────────────────────────────────

    /** @return Collection<string, \App\Models\ProductVariation> */
    public function lockVariants(array $variantIds): Collection;

    /** @return Collection<string, CartItem> */
    public function lockCartItems(string $cartId, array $variantIds = []): Collection;

    public function lockAllCartItems(string $cartId): Collection;

    /** @return array{userCart: Cart|null, guestCart: Cart|null} */
    public function lockBothCartsOrdered(string $userCartId, string $guestCartId): array;

    // ── Item writes ───────────────────────────────────────────────────────────

    public function createItem(string $cartId, string $variantId, int $quantity): CartItem;

    public function updateItemQuantity(CartItem $item, int $quantity): void;

    public function deleteItem(CartItem $item): void;

    public function deleteItemByVariant(string $cartId, string $variantId): void;

    public function deleteItemsExcept(string $cartId, array $keepVariantIds): void;

    public function deleteAllItems(string $cartId): void;

    /** @param list<array{cart_id:string,product_variant_id:string,quantity:int,created_at:\Carbon\CarbonImmutable,updated_at:\Carbon\CarbonImmutable}> $rows */
    public function bulkInsertItems(array $rows): void;

    // ── Cart writes ───────────────────────────────────────────────────────────

    public function deleteCart(Cart $cart): void;

    // ── Read / hydration ──────────────────────────────────────────────────────

    // public function loadForResponse(\App\Models\Cart $cart): \App\Models\Cart;
    public function loadCartForResponse(Cart $cart): Cart;

    public function findCartItem(string $cartId, string $variantId): ?CartItem;

    /**
     * @return \Illuminate\Support\Collection<string, CartItem>
     */
    public function findCartItems(string $cartId, array $variantIds): \Illuminate\Support\Collection;
}
