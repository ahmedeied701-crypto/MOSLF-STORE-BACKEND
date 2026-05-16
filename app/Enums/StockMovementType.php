<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * StockMovementType Enum
 *
 * Centralizes ALL knowledge about movement direction and labeling.
 * To add a new movement type (e.g., 'transfer_in'), simply add a case here.
 * Zero changes required in actions, models, or controllers.
 */
enum StockMovementType: string
{
    // ── Additive (increase stock) ─────────────────────────────────────────────
    case Purchase        = 'purchase';
    case Return          = 'return';
    case AdjustmentAdd   = 'adjustment_add';

        // ── Subtractive (decrease stock) ─────────────────────────────────────────
    case Sale            = 'sale';
    case AdjustmentSub   = 'adjustment_subtract';
    case Damage          = 'damage';
    case Expiry          = 'expiry';

    // ─── Direction Logic ──────────────────────────────────────────────────────

    public function direction(): string
    {
        return match ($this) {
            self::Purchase,
            self::Return,
            self::AdjustmentAdd => 'in',

            self::Sale,
            self::AdjustmentSub,
            self::Damage,
            self::Expiry => 'out',
        };
    }

    public function isAdditive(): bool
    {
        return $this->direction() === 'in';
    }

    public function isSubtractive(): bool
    {
        return $this->direction() === 'out';
    }

    public function label(): string
    {
        return match ($this) {
            self::Purchase      => 'Purchase / Restock',
            self::Return        => 'Customer Return',
            self::AdjustmentAdd => 'Manual Adjustment (Add)',
            self::Sale          => 'Sale',
            self::AdjustmentSub => 'Manual Adjustment (Subtract)',
            self::Damage        => 'Damaged / Write-off',
            self::Expiry        => 'Expired',
        };
    }
}
