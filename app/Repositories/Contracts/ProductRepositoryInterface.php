<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\DTOs\Product\ProductFilterData;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;



interface ProductRepositoryInterface
{
    public function paginate(ProductFilterData $filters): LengthAwarePaginator;
}
