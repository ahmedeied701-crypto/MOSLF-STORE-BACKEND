<?php

declare(strict_types=1);

namespace App\DTOs\Product;

use App\Enums\ProductStatus;

final class ProductFilterData
{
    public function __construct(
        public readonly ?string $search = null,
        public readonly ?ProductStatus $status = null,
        public readonly ?float $priceMin = null,
        public readonly ?float $priceMax = null,
        public readonly string $sortBy = 'created_at',
        public readonly string $sortDir = 'desc',
        public readonly int $perPage = 15,
    ) {}

    public static function fromRequest($request): self
    {
        $statusRaw = $request->query('status');
        return new self(
            search: $request->query('search'),
            status: in_array($statusRaw, ['active', 'inactive', 'archived'])
                ? ProductStatus::from($statusRaw)
                : null,
            priceMin: $request->filled('price_min') ? (float) $request->query('price_min') : null,
            priceMax: $request->filled('price_max') ? (float) $request->query('price_max') : null,
            sortBy: $request->query('sort_by', 'created_at'),
            sortDir: $request->query('sort_dir', 'desc'),
            perPage: (int) $request->query('per_page', 15),

        );
    }

    public function normalizedSortBy(): string
    {
        return in_array($this->sortBy, ['created_at', 'price', 'name'], true)
            ? $this->sortBy
            : 'created_at';
    }

    public function normalizedSortDir(): string
    {
        return $this->sortDir === 'asc' ? 'asc' : 'desc';
    }
}
