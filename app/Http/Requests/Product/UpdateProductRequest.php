<?php

declare(strict_types=1);

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $productId = $this->route('product')?->id;

        return [
            'name'        => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'sku'         => ['sometimes', 'string', 'max:100', Rule::unique('products', 'sku')->ignore($productId)],
            'price'       => ['sometimes', 'numeric', 'min:0', 'max:99999999.99'],
            'cost_price'  => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'currency'    => ['sometimes', 'string', 'size:3'],
            'status'      => ['sometimes', Rule::in(['active', 'inactive', 'archived'])],
            'metadata'    => ['sometimes', 'nullable', 'array'],
            'metadata.*'  => ['nullable', 'string', 'max:1000'],

            'variations.*.id' => ['sometimes', 'nullable', 'integer', 'exists:product_variations,id'],
            'variations.*.status' => ['nullable', Rule::in(['active', 'inactive', 'archived'])],
            'variations.*.sku' => ['sometimes', 'string', 'max:100'],
            'variations.*.price' => ['sometimes', 'numeric', 'min:0'],
            'variations.*.attributes' => ['sometimes', 'array'],

            'variations.*.images' => ['sometimes', 'array'],

            'variations.*.images.*.id' => ['sometimes', 'integer'],
            'variations.*.images.*.file' => ['sometimes', 'image', 'mimes:jpeg,png,jpg,webp', 'max:5120'],
            'variations.*.images.*.type' => ['sometimes', Rule::in(['canvas', 'gallery'])],

            'variations.*.images.*.side' => [
                'sometimes',
                Rule::in(['front', 'back', 'left', 'right']),
            ],

            'variations.*.images.*.is_default' => ['sometimes', 'boolean'],
            'variations.*.images.*.sort_order' => ['sometimes', 'integer'],

        ];
    }
}
