<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'cartKey' => $this->id,
            'items' => $this->items->map(function ($item) {
                $variant = $item->productVariant;
                $product = $variant?->product;

                return [
                    'variantId' => (string) ($variant?->id),
                    'productId' => (string) (string) ($product?->id),
                    'productName' => $product?->name ?? 'Product Not Found',
                    'slug'        => $product?->slug ?? '',
                    'price'       => (float) ($variant?->price ?? 0),
                    'quantity' => $item->quantity,
                    'image'       => $variant?->images?->first()?->image_url,
                    'options'     => [
                        'Color' => $variant?->color,
                        'Size'  => $variant?->size,
                    ],
                    'isCustomized' => false,
                    'customData' => null,
                    'customId' => null,
                ];
            })->filter()->values()->toArray(),
        ];
    }
}
