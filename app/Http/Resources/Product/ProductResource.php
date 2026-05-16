<?php

declare(strict_types=1);

namespace App\Http\Resources\Product;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ProductResource
 *
 * Transforms a Product model into a standardized JSON envelope.
 * Decouples the API contract from the database schema — renaming a DB column
 * never breaks API consumers.
 */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {

        $isAdmin = $request->user()?->hasRole('admin');

        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'slug'        => $this->slug,
            'description' => $this->description,


            'status' => $this->when($isAdmin, $this->status),

            'metadata' => $this->metadata ?? [],

            'variations' => $this->whenLoaded('variations', function () use ($isAdmin) {
                return $this->variations->map(function ($v) use ($isAdmin) {

                    $data = [
                        'id' => $v->id,
                        'status' => $v->status,
                        'sku' => $v->sku,
                        'price' => (float) $v->price,
                        'attributes' => $v->attributes ?? [],

                        // --- Added Media Object ---
                        'media' => [
                            // Base images for the customization engine (Fabric.js)
                            'canvas' => $v->images->where('type', 'canvas')->map(fn($img) => [
                                'side' => $img->side,
                                'url'  => $img->url,
                            ])->values(),

                            // General product gallery/lifestyle images
                            'gallery' => $v->images->where('type', 'gallery')->map(fn($img) => [
                                'url'        => $img->url,
                                'is_default' => $img->is_default,
                            ])->values(),
                        ],
                    ];

                    if ($isAdmin) {
                        $data['stock_quantity'] = $v->stock_quantity;
                    }

                    return $data;
                });
            }),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
