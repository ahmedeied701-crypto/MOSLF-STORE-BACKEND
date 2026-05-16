<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserDashboardResource;
use App\Models\User;
use Illuminate\Http\Request;

class UserDashboardController extends Controller
{
    public function index()
    {
        $user = request()->user()->load([
            'orders' => function ($query) {
                $query->orderBy('created_at', 'desc')->limit(5);
            },
            'subscriptions',
            'wishlist.product.variations.images',
            'cart.items.productVariant.product',
            'cart.items.productVariant.images'
        ]);

        return new UserDashboardResource($user);
    }
    public function wishlist(Request $request)
    {
        $user = $request->user();

        $wishlist = $user->wishlist()
            ->with('product.variations.images')
            ->get();
        $wishlistTransformed = $wishlist->map(function ($wish) {
            $product = $wish->product;
            if (!$product) return null;

            $variation = $product->variations->first() ?? null;
            return [
                'id' => $wish->id,
                'product' => [
                    'id' => $product->id,
                    'slug' => $product->slug,
                    'name' => $product->name,
                    'image' => $variation?->images->first()?->image_path ?? null,
                    'price' => $variation?->price ?? 0,
                ],
            ];
        })->filter()->values();

        return response()->json([
            'data' => $wishlistTransformed
        ]);
    }
    public function addToWishlist(Request $request, $productId)
    {
        $user = $request->user();

        // Check if already exists
        $exists = $user->wishlist()->where('product_id', $productId)->first();
        if ($exists) {
            return response()->json(['message' => 'Product already in wishlist'], 200);
        }

        $wishlist = $user->wishlist()->create(['product_id' => $productId]);

        return response()->json([
            'message' => 'Added to wishlist',
            'data' => $wishlist->load('product.variations.images')
        ]);
    }

    public function removeFromWishlist(Request $request, $productId)
    {
        $user = $request->user();

        $wishlistItem = $user->wishlist()->where('product_id', $productId)->first();
        if (!$wishlistItem) {
            return response()->json(['message' => 'Product not in wishlist'], 404);
        }

        $wishlistItem->delete();

        return response()->json(['message' => 'Removed from wishlist']);
    }
}
