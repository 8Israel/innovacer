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

    /** Applied from a full physical count of a warehouse (PhysicalCountService); goes either way. */
    case PhysicalCount = 'physical_count';

    public function label(): string
    {
        return match ($this) {
            self::Shortage => __('Faltante sin explicar'),
            self::Damage => __('Merma o producto dañado'),
            self::CaptureError => __('Error de captura anterior'),
            self::Surplus => __('Sobrante'),
            self::PhysicalCount => __('Conteo físico'),
        };
    }

    /**
     * Reasons offered when adjusting a single product by hand.
     *
     * @return list<self>
     */
    public static function manual(): array
    {
        return [self::Shortage, self::Damage, self::CaptureError, self::Surplus];
    }

    public function allowsShortage(): bool
    {
        return $this !== self::Surplus;
    }

    public function allowsSurplus(): bool
    {
        return in_array($this, [self::Surplus, self::CaptureError, self::PhysicalCount], true);
    }
}
