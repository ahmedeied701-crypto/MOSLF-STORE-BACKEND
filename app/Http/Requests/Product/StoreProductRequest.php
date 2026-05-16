<?php

declare(strict_types=1);

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Replace with Gate check: e.g., $this->user()->can('create', Product::class)
    }

    public function rules(): array
    {
        return [
            // ── Core Product Fields ────────────────────────────────────────
            'name'             => ['required', 'string', 'max:255'],
            'description'      => ['nullable', 'string', 'max:5000'],
            'sku'              => ['nullable', 'string', 'max:100', 'unique:products,sku'],
            'status'           => ['nullable', Rule::in(['active', 'inactive', 'archived'])],
            'metadata'         => ['nullable', 'array'],
            'metadata.*'       => ['nullable', 'string', 'max:1000'],

            // variations important
            'variations' => ['required', 'array', 'min:1'],
            'variations.*.status' => ['nullable', Rule::in(['active', 'inactive', 'archived'])],
            'variations.*.sku' => ['nullable', 'string', 'max:100'],
            'variations.*.price' => ['required', 'numeric', 'min:0'],
            // 'variations.*.stock' => ['required', 'integer', 'min:0'],
            'variations.*.attributes' => ['required', 'array', 'min:1'],


            'variations.*.images' => ['nullable', 'array'],
            'variations.*.images.*.file' => ['required', 'image', 'mimes:jpeg,png,jpg,webp', 'max:5120'], // Max 5MB
            'variations.*.images.*.type' => ['required', Rule::in(['canvas', 'gallery'])],
            'variations.*.images.*.side' => [
                'nullable',
                Rule::in(['front', 'back', 'left', 'right']),
            ],
            'variations.*.images.*.is_default' => ['boolean'],
            'variations.*.images.*.sort_order' => ['nullable', 'integer'],

            // ── Initial Inventory Fields ───────────────────────────────────
            // 'initial_quantity' => ['nullable', 'integer', 'min:0'],
            'reorder_point'    => ['nullable', 'integer', 'min:0'],
            'reorder_quantity' => ['nullable', 'integer', 'min:0'],
            'location'         => ['nullable', 'string', 'max:255'],
        ];
    }


    public function messages(): array
    {
        return [
            'variations.*.price.required' => 'A selling price is required.',
            'price.numeric'  => 'Price must be a valid number.',
            'sku.unique'     => 'This SKU is already in use by another product.',
        ];
    }
}
