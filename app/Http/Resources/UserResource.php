<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Services\Cart\CartService;


class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // $cart = app(CartService::class)->getCartForRequest($request);
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'cart'       => new CartResource($this->whenLoaded('cart')),
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
        ];
    }
}
