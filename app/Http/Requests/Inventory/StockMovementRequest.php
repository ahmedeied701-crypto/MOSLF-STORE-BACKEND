<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Enums\StockMovementType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type'      => ['required', Rule::enum(StockMovementType::class)],
            'quantity'  => ['required', 'integer', 'min:1'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes'     => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        $validTypes = implode(', ', array_column(StockMovementType::cases(), 'value'));

        return [
            'type.required' => 'A movement type is required.',
            'type.enum'     => "Invalid movement type. Valid types: {$validTypes}",
            'quantity.min'  => 'Quantity must be at least 1.',
        ];
    }
}
