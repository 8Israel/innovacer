<?php

namespace App\Models;

/**
 * Why a physical count did not match the system.
 */
enum AdjustmentReason: string
{
    /** Faltante sin explicar. */
    case Shortage = 'shortage';

    /** Merma o producto dañado. */
    case Damage = 'damage';

    /** Error de captura anterior; can go either way. */
    case CaptureError = 'capture_error';

    /** Sobrante. */
    case Surplus = 'surplus';

    public function label(): string
    {
        return match ($this) {
            self::Shortage => __('Faltante sin explicar'),
            self::Damage => __('Merma o producto dañado'),
            self::CaptureError => __('Error de captura anterior'),
            self::Surplus => __('Sobrante'),
        };
    }

    public function allowsShortage(): bool
    {
        return $this !== self::Surplus;
    }

    public function allowsSurplus(): bool
    {
        return in_array($this, [self::Surplus, self::CaptureError], true);
    }
}
