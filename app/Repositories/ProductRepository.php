<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Product;
use App\Repositories\Contracts\ProductRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use App\DTOs\Product\ProductFilterData;

class ProductRepository implements ProductRepositoryInterface
{

    /**
     * Paginated product list, passed through the query pipeline.
     *
     * Adding a new filter = create a new Pipe class + add it to $pipes[].
     * The controller and this method never change.
     */
    public function paginate(ProductFilterData $filters): LengthAwarePaginator
    {
        $query = Product::query()->withAdminRelations();

        if ($filters->status !== null) {
            if ($filters->status->value === 'archived') {
                $query->onlyTrashed();
            } else {
                $query->where('status', $filters->status->value);
            }
        } else {
            $query->whereIn('status', ['active', 'inactive']);
        }

        if ($filters->search) {
            $query->where('name', 'like', '%' . $filters->search . '%');
        }

        if ($filters->priceMin !== null) {
            $query->whereHas('variations', function ($q) use ($filters) {
                $q->where('price', '>=', $filters->priceMin);
            });
        }

        if ($filters->priceMax !== null) {
            $query->whereHas('variations', function ($q) use ($filters) {
                $q->where('price', '<=', $filters->priceMax);
            });
        }

        $query->orderBy(
            $filters->sortBy,
            $filters->sortDir
        );

        return $query->paginate(
            $filters->perPage
        );
    }
}
