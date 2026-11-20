<?php

namespace App\Services;

use App\Concerns\RoundsDecimals;
use App\Models\CustomerReturn;
use App\Models\CustomerReturnItem;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationType;
use App\Models\RefundMethod;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registers customer returns: the refund note (D-000001), the refund and, unless
 * the product is waste, its way back into the lots it was sold from.
 */
class CustomerReturnService
{
    use RoundsDecimals;

    private const MONEY = 2;

    private const QUANTITY = 3;

    public function __construct(
        private CashSessionService $cashSessions,
        private InventoryService $inventory,
        private FolioSequence $folios,
    ) {}

    /**
     * Each returned unit is refunded at what the customer actually paid for it
     * (its share of the item total, discounts and taxes included). A cash refund
     * leaves the open register; an account credit lowers what the customer owes.
     *
     * @param  list<array{sale_item_id: int, quantity: string}>  $items
     */
    public function register(
        User $user,
        Sale $sale,
        array $items,
        RefundMethod $refundMethod,
        bool $restock = true,
        ?string $reason = null,
        ?string $reference = null,
    ): CustomerReturn {
        if ($items === []) {
            throw ValidationException::withMessages(['items' => __('Marca al menos un producto que regresa el cliente.')]);
        }

        if (count(array_unique(array_column($items, 'sale_item_id'))) !== count($items)) {
            throw ValidationException::withMessages(['items' => __('Cada producto de la venta se indica una sola vez.')]);
        }

        return DB::transaction(function () use ($user, $sale, $items, $refundMethod, $restock, $reason, $reference): CustomerReturn {
            $sale = Sale::query()->with('warehouse')->lockForUpdate()->findOrFail($sale->id);

            if ($sale->status !== SaleStatus::Completed) {
                throw ValidationException::withMessages(['sale' => __('Solo se pueden devolver productos de ventas cobradas.')]);
            }

            if ($refundMethod === RefundMethod::AccountCredit && $sale->customer_id === null) {
                throw ValidationException::withMessages([
                    'refund_method' => __('Una venta sin cliente no se puede abonar a cuenta.'),
                ]);
            }

            $session = $refundMethod === RefundMethod::Cash
                ? $this->cashSessions->requireOpen()
                : $this->cashSessions->current();

            $lines = $this->refundLines($sale, $items);
            $refundTotal = array_reduce($lines, fn (string $carry, array $line) => bcadd($carry, $line[2], self::MONEY), '0');
            $folio = $this->folios->next(CustomerReturn::class, 'D');

            $operation = null;

            if ($restock) {
                $operation = InventoryOperation::create([
                    'type' => InventoryOperationType::Return,
                    'user_id' => $user->id,
                    'reference' => $folio,
                    'note' => $reason,
                    'occurred_at' => now(),
                ]);

                foreach ($lines as [$saleItem, $quantity]) {
                    $this->inventory->restockReturn($operation, $saleItem, $quantity, $sale->warehouse);
                }
            }

            $customerReturn = CustomerReturn::create([
                'cash_session_id' => $session?->id,
                'folio' => $folio,
                'sale_id' => $sale->id,
                'customer_id' => $sale->customer_id,
                'warehouse_id' => $sale->warehouse_id,
                'user_id' => $user->id,
                'inventory_operation_id' => $operation?->id,
                'restocked' => $restock,
                'refund_method' => $refundMethod,
                'refund_amount' => $refundTotal,
                'reference' => $reference,
                'reason' => $reason,
                'returned_at' => now(),
            ]);

            foreach ($lines as [$saleItem, $quantity, $refund]) {
                $customerReturn->items()->create([
                    'sale_item_id' => $saleItem->id,
                    'product_id' => $saleItem->product_id,
                    'quantity' => $quantity,
                    'refund_amount' => $refund,
                ]);
            }

            return $customerReturn;
        });
    }

    /**
     * @param  list<array{sale_item_id: int, quantity: string}>  $items
     * @return list<array{0: SaleItem, 1: string, 2: string}> sale item, quantity and refund.
     */
    private function refundLines(Sale $sale, array $items): array
    {
        $saleItems = $sale->items()->with('product')->get()->keyBy('id');

        return array_map(function (array $item) use ($saleItems): array {
            $saleItem = $saleItems->get($item['sale_item_id'] ?? null)
                ?? throw ValidationException::withMessages(['items' => __('Uno de los productos no es de esta venta.')]);

            $quantity = $item['quantity'] ?? null;

            if (! is_numeric($quantity) || $quantity <= 0) {
                throw ValidationException::withMessages(['items' => __('La cantidad a devolver debe ser mayor a cero.')]);
            }

            $quantity = bcadd((string) $quantity, '0', self::QUANTITY);
            $alreadyReturned = bcadd((string) (CustomerReturnItem::query()->where('sale_item_id', $saleItem->id)->sum('quantity') ?: '0'), '0', self::QUANTITY);
            $returnable = bcsub($saleItem->quantity, $alreadyReturned, self::QUANTITY);

            if (bccomp($quantity, $returnable, self::QUANTITY) === 1) {
                throw ValidationException::withMessages([
                    'items' => __('De :product solo se pueden devolver :returnable.', [
                        'product' => $saleItem->product->name,
                        'returnable' => rtrim(rtrim($returnable, '0'), '.') ?: '0',
                    ]),
                ]);
            }

            $refund = $this->roundHalfUp(bcdiv(bcmul($saleItem->total, $quantity, 8), $saleItem->quantity, 8), self::MONEY);

            return [$saleItem, $quantity, $refund];
        }, array_values($items));
    }
}
