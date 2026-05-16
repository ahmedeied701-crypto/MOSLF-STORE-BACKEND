<?php

declare(strict_types=1);

namespace App\Repositories\Public;

use App\Models\Product;
use App\DTOs\Product\PublicProductFilterData;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use App\Repositories\Public\Contracts\ProductPublicRepositoryInterface;

class ProductPublicRepository implements ProductPublicRepositoryInterface
{
    public function paginate(PublicProductFilterData $filters): LengthAwarePaginator
    {
        $query = Product::query()
            ->with(['variations.inventory'])
            ->active();

        if ($filters->search) {
            $query->where('name', 'like', "%{$filters->search}%");
        }

        if ($filters->priceMin) {
            $query->whereHas(
                'variations',
                fn($q) =>
                $q->where('price', '>=', $filters->priceMin)
            );
        }

        if ($filters->priceMax) {
            $query->whereHas(
                'variations',
                fn($q) =>
                $q->where('price', '<=', $filters->priceMax)
            );
        }

        return $query->paginate($filters->perPage ?: 15);
    }
}
