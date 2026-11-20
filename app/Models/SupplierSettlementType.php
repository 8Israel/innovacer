<?php

namespace App\Models;

/**
 * How a supplier is settled for the merchandise it leaves.
 */
enum SupplierSettlementType: string
{
    /** Bought outright; paid on receipt or on credit. */
    case Purchase = 'purchase';

    /** Paid in money only for what gets sold. */
    case Consignment = 'consignment';

    /** Lends product that is repaid with the same product (Francisco). */
    case InKind = 'in_kind';

    public function label(): string
    {
        return match ($this) {
            self::Purchase => __('Compra'),
            self::Consignment => __('Consigna'),
            self::InKind => __('En especie'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Purchase => __('se paga lo recibido'),
            self::Consignment => __('se paga lo vendido'),
            self::InKind => __('se le repone el producto'),
        };
    }
}
