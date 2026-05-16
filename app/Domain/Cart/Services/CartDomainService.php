<?php

declare(strict_types=1);

// =============================================================================
// app/Services/Cart/CartDomainService.php
// =============================================================================

namespace App\Domain\Cart\Services;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ProductVariation;
use App\Services\Cart\Contracts\CartRepositoryInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Pure business-logic layer for the Cart bounded context.
 *
 * Responsibilities
 * ────────────────
 *  • Stock clamping and availability decisions.
 *  • Merge quantity arithmetic (guest → user).
 *  • Diff-based persist planning.
 *  • Cart state evaluation (checkout eligibility, totals, etc.).
 *
 * Hard constraints
 * ────────────────
 *  • Zero dependency on DB, Request, or any facade.
 *  • Stateless — no instance state mutated between calls.
 *  • Every public method receives already-locked models from the orchestrator.
 *  • All writes are delegated to CartRepositoryInterface; never direct Eloquent.
 *
 * Concurrency contract
 * ────────────────────
 *  Every model and collection received by these methods MUST already hold a
 *  write lock acquired by CartRepository inside an active DB::transaction().
 *  This service never acquires locks itself.
 */
final class CartDomainService
{
    /**
     * Minimum storable quantity for any cart item.
     * Quantities that would fall below this are treated as removals.
     */
    private const MIN_QUANTITY = 1;

    public function __construct(
        private readonly CartRepositoryInterface $repo,
    ) {}

    // =========================================================================
    // STOCK CLAMPING  (pure — no I/O)
    // =========================================================================

    /**
     * Clamp a requested quantity against a locked variant's available stock.
     *
     * Rules
     * ─────
     *  • Stock = 0           → null  (caller must treat as out-of-stock / remove)
     *  • Requested < 1       → clamped to MIN_QUANTITY (never store 0 or negative)
     *  • Requested > stock   → clamped down to stock
     *  • Otherwise           → requested unchanged
     *
     * The variant MUST be locked by the caller before invoking this method to
     * guarantee the stock value is not stale.
     *
     * @param  ProductVariation $variant   Locked variant model.
     * @param  int              $requested Raw quantity before clamping.
     * @return int|null                    Safe quantity, or null when out-of-stock.
     */
    public function clampQty(ProductVariation $variant, int $requested): ?int
    {
        if ($variant->stock < 1) {
            return null;
        }

        return min(max(self::MIN_QUANTITY, $requested), $variant->stock);
    }

    /**
     * Determine whether a variant has sufficient stock for a given quantity.
     * Does NOT clamp — use clampQty() when you also need the safe value.
     *
     * @param ProductVariation $variant   Locked variant model.
     * @param int              $quantity  Quantity to test (must be >= 1).
     */
    public function hasStock(ProductVariation $variant, int $quantity): bool
    {
        return $variant->stock >= max(self::MIN_QUANTITY, $quantity);
    }

    // =========================================================================
    // UPSERT  (additive quantity change for a single variant)
    // =========================================================================

    /**
     * Add $requestedQty on top of the existing item quantity, clamped to stock.
     *
     * Returns the final stored quantity, or null when the variant is out of
     * stock (caller is responsible for throwing the appropriate exception).
     *
     * Write behaviour
     * ───────────────
     *  • Item exists  → update quantity to (existing + requested) clamped.
     *  • Item absent  → create new item with clamped quantity.
     *  • Out of stock → no write, returns null.
     *
     * @param  Cart             $cart         Cart that owns the item.
     * @param  ProductVariation $variant      Locked variant.
     * @param  CartItem|null    $existing     Locked existing item, or null.
     * @param  int              $requestedQty Quantity to add (>= 1 guaranteed by caller).
     * @return int|null                       Final quantity written, or null if OOS.
     */
    public function applyUpsert(
        Cart             $cart,
        ProductVariation $variant,
        ?CartItem        $existing,
        int              $requestedQty,
    ): ?int {
        $existing?->refresh();
        
        $baseQty = $existing?->quantity ?? 0;
        $safeQty = $this->clampQty($variant, $baseQty + $requestedQty);

        if ($safeQty === null) {
            if ($existing !== null) {
                $this->repo->deleteItem($existing);
            }
            return null;
        }

        if ($existing !== null) {
            $this->repo->updateItemQuantity($existing, $safeQty);
        } else {
            $this->repo->createItem((string) $cart->id, (string) $variant->id, $safeQty);
        }

        return $safeQty;
    }

    // =========================================================================
    // SET QUANTITY  (exact quantity override for a single variant)
    // =========================================================================

    /**
     * Override a cart item's quantity to an exact value, clamped to stock.
     *
     * Write behaviour
     * ───────────────
     *  • Variant missing or OOS → delete the item (stale / unavailable).
     *  • Quantity unchanged     → no write (avoids redundant DB round-trip).
     *  • Otherwise              → update to clamped value.
     *
     * Callers that receive quantity <= 0 should delegate to removeItem instead
     * of calling this method, preserving a single removal path.
     *
     * @param ProductVariation|null $variant      Locked variant, or null if deleted.
     * @param CartItem              $item         Locked item to modify.
     * @param int                   $requestedQty Desired absolute quantity (>= 1).
     */
    public function applySetQuantity(
        ?ProductVariation $variant,
        CartItem          $item,
        int               $requestedQty,
    ): void {
        if ($variant === null) {
            // Variant no longer exists in the catalogue — prune the orphan.
            $this->repo->deleteItem($item);
            return;
        }

        $safeQty = $this->clampQty($variant, $requestedQty);

        if ($safeQty === null) {
            // Variant exists but is out of stock — remove from cart.
            $this->repo->deleteItem($item);
            return;
        }

        if ($item->quantity === $safeQty) {
            // No change — skip the write entirely.
            return;
        }

        $this->repo->updateItemQuantity($item, $safeQty);
    }

    // =========================================================================
    // MERGE  (guest cart → user cart)
    // =========================================================================

    /**
     * Merge all guest cart items into the user cart using additive semantics.
     *
     * Algorithm (per guest item)
     * ──────────────────────────
     *  1. Variant absent from DB    → skip (catalogue has removed it).
     *  2. Variant OOS               → skip (do not add unavailable stock).
     *  3. User already has variant  → sum quantities, clamp, update.
     *  4. User does not have it     → stage for bulk insert.
     *
     * All decisions are made in-memory against pre-locked collections.
     * Updates are written individually (already locked rows).
     * Creates are batched into a single INSERT for performance.
     *
     * @param Cart                                   $userCart   Locked user cart.
     * @param Collection<string, CartItem>           $guestItems Locked guest items, keyed by variant id.
     * @param Collection<string, ProductVariation>   $variants   Locked variants,    keyed by id.
     * @param Collection<string, CartItem>           $userItems  Locked user items,  keyed by variant id.
     */
    public function applyMerge(
        Cart       $userCart,
        Collection $guestItems,
        Collection $variants,
        Collection $userItems,
    ): void {
        $creates = [];
        $now     = CarbonImmutable::now();

        foreach ($guestItems as $variantId => $guestItem) {
            $variant = $variants->get($variantId);

            if ($variant === null) {
                // Variant removed from catalogue since guest added it — skip.
                continue;
            }

            $userItem = $userItems->get($variantId);
            $baseQty  = $userItem?->quantity ?? 0;
            $safeQty  = $this->clampQty($variant, $baseQty + $guestItem->quantity);

            if ($safeQty === null) {
                // OOS — do not pollute the user cart with unavailable items.
                continue;
            }

            if ($userItem !== null) {
                if ($userItem->quantity !== $safeQty) {
                    $this->repo->updateItemQuantity($userItem, $safeQty);
                }
            } else {
                $creates[] = [
                    'cart_id'            => (string) $userCart->id,
                    'product_variant_id' => (string) $variantId,
                    'quantity'           => $safeQty,
                    'created_at'         => $now,
                    'updated_at'         => $now,
                ];
            }
        }

        $this->repo->bulkInsertItems($creates);
    }

    // =========================================================================
    // PERSIST  (full cart replace from external payload)
    // =========================================================================

    /**
     * Diff an incoming payload against the locked DB state and write minimally.
     *
     * Algorithm (per incoming item)
     * ─────────────────────────────
     *  1. Variant absent from DB     → delete existing item if any, skip.
     *  2. Variant OOS                → delete existing item if any, skip.
     *  3. Quantity unchanged         → no write (idempotency guard).
     *  4. Item exists, qty differs   → update.
     *  5. Item absent                → stage for bulk insert.
     *
     * Stale-item removal (variants in DB but not in payload) is handled upstream
     * by CartRepository::deleteItemsExcept() before this method is called.
     *
     * @param string                                 $cartId        Owning cart PK.
     * @param Collection<string, int>                $incoming      variantId → requested qty.
     * @param Collection<string, ProductVariation>   $variants      Locked variants, keyed by id.
     * @param Collection<string, CartItem>           $existingItems Locked items,    keyed by variant id.
     */
    public function applyPersist(
        string     $cartId,
        Collection $incoming,
        Collection $variants,
        Collection $existingItems,
    ): void {
        $creates = [];
        $now     = CarbonImmutable::now();

        foreach ($incoming as $variantId => $requestedQty) {
            $variant  = $variants->get($variantId);
            $existing = $existingItems->get($variantId);

            if ($variant === null) {
                // Unknown variant — purge any leftover item.
                if ($existing !== null) {
                    $this->repo->deleteItem($existing);
                }
                continue;
            }

            $safeQty = $this->clampQty($variant, $requestedQty);

            if ($safeQty === null) {
                // Out of stock — cannot store this item.
                if ($existing !== null) {
                    $this->repo->deleteItem($existing);
                }
                continue;
            }

            if ($existing !== null) {
                if ($existing->quantity !== $safeQty) {
                    $this->repo->updateItemQuantity($existing, $safeQty);
                }
                // Quantity identical — no write needed (idempotent).
            } else {
                $creates[] = [
                    'cart_id'            => $cartId,
                    'product_variant_id' => (string) $variantId,
                    'quantity'           => $safeQty,
                    'created_at'         => $now,
                    'updated_at'         => $now,
                ];
            }
        }

        $this->repo->bulkInsertItems($creates);
    }

    // =========================================================================
    // TOTALS & ITEM CALCULATIONS  (pure — no I/O)
    // =========================================================================

    /**
     * Compute the monetary subtotal for a single cart item.
     *
     * Returns 0.0 when the variant relation is not loaded (defensive guard).
     *
     * @param  CartItem $item  Item with productVariant relation loaded.
     * @return float           Subtotal in the variant's currency unit.
     */
    public function itemSubtotal(CartItem $item): float
    {
        $price = (float) ($item->productVariant?->price ?? 0.0);

        return round($price * $item->quantity, 2);
    }

    /**
     * Compute the total monetary value of all items in a loaded Cart.
     *
     * Relations required: items.productVariant
     *
     * @param  Cart  $cart  Cart with items and productVariant relations loaded.
     * @return float        Grand total in the cart's currency unit.
     */
    public function cartTotal(Cart $cart): float
    {
        return round(
            $cart->items->sum(fn(CartItem $item): float => $this->itemSubtotal($item)),
            2
        );
    }

    /**
     * Compute the aggregate item count (sum of quantities, not distinct lines).
     *
     * @param Cart $cart  Cart with items relation loaded.
     * @return int        Total number of units across all line items.
     */
    public function totalItemCount(Cart $cart): int
    {
        return (int) $cart->items->sum('quantity');
    }

    /**
     * Return the number of distinct product variant lines in the cart.
     *
     * @param Cart $cart  Cart with items relation loaded.
     * @return int        Number of distinct line items.
     */
    public function distinctLineCount(Cart $cart): int
    {
        return $cart->items->count();
    }

    // =========================================================================
    // STATE EVALUATION  (pure — no I/O)
    // =========================================================================

    /**
     * Determine whether the cart meets the minimum criteria for checkout.
     *
     * Rules (all must pass)
     * ─────────────────────
     *  • At least one item present.
     *  • No item carries a zero or negative price (guards against stale data).
     *  • No item quantity exceeds its variant's current stock.
     *
     * Relations required: items.productVariant
     *
     * @param  Cart $cart  Cart with items.productVariant loaded.
     * @return bool        True when the cart can proceed to checkout.
     */
    public function isEligibleForCheckout(Cart $cart): bool
    {
        if ($cart->items->isEmpty()) {
            return false;
        }

        foreach ($cart->items as $item) {
            $variant = $item->productVariant;

            // Unresolvable variant — catalogue integrity failure.
            if ($variant === null) {
                return false;
            }

            // Negative or free items indicate corrupted / stale pricing.
            if ((float) $variant->price <= 0.0) {
                return false;
            }

            // Stock may have changed since item was added.
            if ($item->quantity > $variant->stock) {
                return false;
            }
        }

        return true;
    }

    /**
     * Identify line items whose requested quantity now exceeds available stock.
     *
     * Useful for surfacing "X items in your cart are no longer available in the
     * requested quantity" warnings to the customer before checkout.
     *
     * Relations required: items.productVariant
     *
     * @param  Cart                      $cart  Cart with items.productVariant loaded.
     * @return Collection<int, CartItem>        Items that exceed current stock.
     */
    public function itemsExceedingStock(Cart $cart): Collection
    {
        return $cart->items->filter(
            fn(CartItem $item): bool =>
            $item->productVariant === null
                || $item->quantity > $item->productVariant->stock
        )->values();
    }

    /**
     * Identify line items whose variant is entirely out of stock (stock = 0).
     *
     * Relations required: items.productVariant
     *
     * @param  Cart                      $cart  Cart with items.productVariant loaded.
     * @return Collection<int, CartItem>        Items with zero available stock.
     */
    public function outOfStockItems(Cart $cart): Collection
    {
        return $cart->items->filter(
            fn(CartItem $item): bool =>
            $item->productVariant === null
                || $item->productVariant->stock < 1
        )->values();
    }

    /**
     * Produce a summary snapshot of the cart's current business state.
     *
     * This is a read-only projection — no writes, no locks required.
     * Intended for API responses that need richer cart metadata.
     *
     * Relations required: items.productVariant
     *
     * @param  Cart $cart  Fully loaded cart.
     * @return array{
     *     total: float,
     *     item_count: int,
     *     line_count: int,
     *     is_eligible_for_checkout: bool,
     *     has_out_of_stock_items: bool,
     *     has_stock_exceeded_items: bool,
     * }
     */
    public function summarise(Cart $cart): array
    {
        $outOfStock     = $this->outOfStockItems($cart);
        $exceededStock  = $this->itemsExceedingStock($cart);

        return [
            'total'                    => $this->cartTotal($cart),
            'item_count'               => $this->totalItemCount($cart),
            'line_count'               => $this->distinctLineCount($cart),
            'is_eligible_for_checkout' => $this->isEligibleForCheckout($cart),
            'has_out_of_stock_items'   => $outOfStock->isNotEmpty(),
            'has_stock_exceeded_items' => $exceededStock->isNotEmpty(),
        ];
    }
}
