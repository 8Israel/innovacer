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
}
