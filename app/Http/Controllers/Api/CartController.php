<?php

declare(strict_types=1);

// =============================================================================
// app/Http/Controllers/Api/CartController.php
// =============================================================================

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Cart\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HTTP entry-point for the Cart API.
 *
 * Responsibilities
 * ────────────────
 *  • Validate raw HTTP input.
 *  • Delegate entirely to CartService.
 *  • Format JSON responses.
 *
 * Hard constraints
 * ────────────────
 *  • No business logic.
 *  • No direct DB/model access.
 *  • Every action returns JsonResponse with an explicit HTTP status.
 */
final class CartController extends Controller
{
    public function __construct(
        private readonly CartService $cartService,
    ) {}

    // =========================================================================
    // GET  /api/cart
    // =========================================================================

    /**
     * Return the current cart for the request owner (user or guest).
     */
    public function get(Request $request): JsonResponse
    {
        $cart = $this->cartService->getCartForRequest($request);

        $response = response()->json($this->shape($cart));

        // IMPORTANT: always persist guest identity
        if (!empty($cart['cartKey'])) {
            $response->header('X-Guest-Cart-ID', $cart['cartKey']);

            $response->cookie(
                'guest_cart_id',
                $cart['cartKey'],
                60 * 24 * 30,
                '/',
                null,
                false,
                false
            );
        }

        return $response;
    }
    

    // =========================================================================
    // POST  /api/cart/merge
    // =========================================================================

    /**
     * Merge a guest cart into the authenticated user's cart.
     * Called client-side immediately after login.
     */
    public function merge(Request $request): JsonResponse
    {
        $request->validate([
            'guest_cart_id' => ['required', 'uuid', 'exists:carts,id'],
        ]);

        $cart = $this->cartService->mergeGuestCart($request);

        // mergeGuestCart() returns [] when preconditions fail (e.g. no auth).
        if (empty($cart)) {
            return response()->json(
                ['error' => 'Cart merge could not be completed.'],
                422
            );
        }

        return response()->json([
            ...$this->shape($cart),
            'message' => 'Cart merged successfully.',
        ]);
    }

    // =========================================================================
    // POST  /api/cart/items
    // =========================================================================

    /**
     * Add quantity to a variant in the cart (additive, not replace).
     */
    public function upsert(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'variantId' => ['required', 'integer', 'exists:product_variations,id'],
            'quantity'  => ['required', 'integer'],
        ]);

        $cart = $this->cartService->upsertItem(
            $request,
            $validated['variantId'],
            (int) $validated['quantity'],
        );

        return response()->json($this->shape($cart), 201);
    }

    // =========================================================================
    // PATCH  /api/cart/items/{variantId}
    // =========================================================================

    /**
     * Set an exact quantity for a specific variant.
     * Sending quantity = 0 removes the item.
     */
    public function setQuantity(Request $request, string $variantId): JsonResponse
    {
        $this->validateVariantId($variantId);

        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:0'],
            'expected_current_quantity' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);

        $cart = $this->cartService->setItemQuantity(
            $request,
            $variantId,
            (int) $validated['quantity'],
            isset($validated['expected_current_quantity'])
                ? (int) $validated['expected_current_quantity']
                : null,
        );

        return response()->json($this->shape($cart));
    }

    // =========================================================================
    // DELETE  /api/cart/items/{variantId}
    // =========================================================================

    /**
     * Remove a variant line item from the cart entirely.
     */
    public function remove(Request $request, string $variantId): JsonResponse
    {
        $this->validateVariantId($variantId);

        $cart = $this->cartService->removeItem($request, $variantId);

        return response()->json($this->shape($cart));
    }

    // =========================================================================
    // INTERNAL HELPERS
    // =========================================================================

    /**
     * Validate a route-parameter variant UUID before it reaches the service.
     * Throws a ValidationException (→ 422) on failure, consistent with all
     * other validation errors in this controller.
     */
    private function validateVariantId(string $variantId): void
    {
        validator(
            ['variantId' => $variantId],
            ['variantId' => ['required', 'exists:product_variations,id']]
        )->validate();
    }

    /**
     * Extract the canonical JSON-serialisable shape from a cart array.
     *
     * Having a single mapping point here means the controller never breaks
     * if CartService adds extra internal keys to its return value.
     *
     * @param  array{cartKey: string|null, items: array<int, mixed>} $cart
     * @return array{cartKey: string|null, items: array<int, mixed>}
     */
    private function shape(array $cart): array
    {
        return [
            'cartKey' => $cart['cartKey'] ?? null,
            'items'   => $cart['items']   ?? [],
            'action'  => $cart['action']  ?? null,
            'reason'  => $cart['reason']  ?? null,
        ];
    }
}
