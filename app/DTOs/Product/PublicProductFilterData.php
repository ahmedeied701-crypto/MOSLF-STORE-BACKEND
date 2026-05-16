<?php

declare(strict_types=1);

namespace App\DTOs\Product;

final class PublicProductFilterData
{
    public function __construct(
        public readonly ?string $search = null,
        public readonly ?float $priceMin = null,
        public readonly ?float $priceMax = null,
        public readonly string $sortBy = 'created_at',
        public readonly string $sortDir = 'desc',
        public readonly int $perPage = 15,
    ) {}

    public static function fromRequest($request): self
    {
        return new self(
            search: $request->query('search'),
            priceMin: $request->filled('price_min') ? (float) $request->query('price_min') : null,
            priceMax: $request->filled('price_max') ? (float) $request->query('price_max') : null,
            sortBy: $request->query('sort_by', 'created_at'),
            sortDir: $request->query('sort_dir', 'desc'),
            perPage: (int) $request->query('per_page', 15),
        );
    }
}
