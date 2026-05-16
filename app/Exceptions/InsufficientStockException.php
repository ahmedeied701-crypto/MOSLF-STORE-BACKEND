<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class InsufficientStockException extends RuntimeException
{
    public function __construct(
        public readonly int $available,
        public readonly int $requested,
    ) {
        parent::__construct(
            "Insufficient stock. Requested: {$requested}, Available: {$available}."
        );
    }
}
