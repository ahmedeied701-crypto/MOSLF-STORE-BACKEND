<?php

declare(strict_types=1);


namespace App\Repositories\Public\Contracts;

use App\DTOs\Product\PublicProductFilterData;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ProductPublicRepositoryInterface
{
    public function paginate(PublicProductFilterData $filters): LengthAwarePaginator;
}
