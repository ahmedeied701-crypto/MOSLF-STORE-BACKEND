<?php

// namespace App\Http\Resources;

// use Illuminate\Http\Request;
// use Illuminate\Http\Resources\Json\JsonResource;
// use Illuminate\Support\Facades\Storage;

// class ProductResource extends JsonResource
// {
//     public function toArray(Request $request): array
//     {
//         return [
//             'id' => $this->id,
//             'name' => $this->name,
//             'description' => $this->description ?? '',
//             'slug' => $this->slug,

//             'variations' => $this->variations->map(function ($variation) {
//                 return [
//                     'id' => $variation->id,
//                     'sku' => $variation->sku,

//                     'price' => round($variation->price, 2),
//                     'priceMinor' => (int) ($variation->price * 100),

//                     'stock_quantity' => $variation->stock_quantity,

//                     'attributes' => $variation->attributes ?? [],

//                     'images' => $variation->images->map(function ($image) {
//                         return [
//                             'id' => $image->id,
//                             'image_url' => $image->image_path
//                                 ? Storage::url($image->image_path)
//                                 : '',
//                             'is_default' => (bool) $image->is_default,
//                         ];
//                     })->toArray(),
//                 ];
//             }),
//         ];
//     }
// }
