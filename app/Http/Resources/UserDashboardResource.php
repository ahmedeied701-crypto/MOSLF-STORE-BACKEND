<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserDashboardResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'points_total' => $this->points_sum_points ?? 0,
            'orders_count' => $this->orders_count ?? 0,

            'cart' => app(\App\Services\Cart\CartService::class)->getCartForRequest($request),

            // recent orders
            'recent_orders' => $this->orders->map(fn($order) => [
                'id' => $order->id,
                'total' => $order->total,
                'status' => $order->status,
                'created_at' => $order->created_at->format('Y-m-d H:i'),
            ]),

            // subscriptions
            'subscriptions' => $this->subscriptions->map(fn($sub) => [
                'id' => $sub->id,
                'plan' => $sub->plan,
            ]),

            // wishlist
            'wishlist' => $this->wishlist->map(function ($wish) {

                $product = $wish->product;

                if (!$product) {
                    return null;
                }

                $variation = $product->variations->first() ?? null;
                $image = $variation?->images->first();

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
            })->filter()->values(),
        ];
    }
}
