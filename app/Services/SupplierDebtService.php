<?php

namespace App\Services;

use App\Concerns\RoundsDecimals;
use App\Models\Lot;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierSettlementType;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * What is owed to a supplier, lot by lot (RF-30). A consignment lot (the supplier
 * owns it) is owed only for what was sold: units that left in sales minus those
 * that came back with returns or cancellations. A bought lot is owed in full when
 * received. Both are owed at the lot's unit cost, less what payments already
 * covered. Suppliers repaid in kind (Francisco) are never owed money.
 */
class SupplierDebtService
{
    use RoundsDecimals;

    private const MONEY = 2;

    private const QUANTITY = 3;

    /**
     * @return Collection<int, array{lot: Lot, consignment: bool, payable_quantity: string, unpaid_quantity: string, owed: string, paid: string, pending: string}>
     */
    public function lots(Supplier $supplier): Collection
    {
        if ($supplier->settlement_type === SupplierSettlementType::InKind) {
            return collect();
        }

        return Lot::query()
            ->with('product')
            ->where('supplier_id', $supplier->id)
            ->where(fn ($lots) => $lots->whereNull('owner_supplier_id')->orWhere('owner_supplier_id', $supplier->id))
            ->selectRaw('lots.*, (select coalesce(sum(quantity), 0) from inventory_movements where inventory_movements.lot_id = lots.id and inventory_movements.sale_item_id is not null) as sold_net')
            ->withSum('paymentAllocations as paid_amount', 'amount')
            ->withSum('paymentAllocations as paid_quantity', 'quantity')
            ->orderBy('received_at')
            ->orderBy('id')
            ->get()
            ->map(function (Lot $lot): array {
                $consignment = $lot->owner_supplier_id !== null;
                $payable = $consignment
                    ? bcsub('0', $this->decimal($lot->sold_net, self::QUANTITY), self::QUANTITY)
                    : $this->decimal($lot->quantity_received, self::QUANTITY);
                $owed = $this->roundHalfUp(bcmul($payable, $lot->unit_cost ?? '0', 8), self::MONEY);
                $paid = $this->decimal($lot->paid_amount, self::MONEY);

                return [
                    'lot' => $lot,
                    'consignment' => $consignment,
                    'payable_quantity' => $payable,
                    'unpaid_quantity' => bcsub($payable, $this->decimal($lot->paid_quantity, self::QUANTITY), self::QUANTITY),
                    'owed' => $owed,
                    'paid' => $paid,
                    'pending' => bcsub($owed, $paid, self::MONEY),
                ];
            });
    }

    public function pending(Supplier $supplier): string
    {
        return $this->lots($supplier)->reduce(fn (string $carry, array $row) => bcadd($carry, $row['pending'], self::MONEY), '0.00');
    }

    /**
     * Pending debt grouped by product, oldest debt first.
     *
     * @return Collection<int, array{product: Product, unpaid_quantity: string, unit_costs: list<string>, pending: string}>
     */
    public function products(Supplier $supplier): Collection
    {
        return $this->lots($supplier)
            ->filter(fn (array $row) => bccomp($row['pending'], '0', self::MONEY) === 1)
            ->groupBy(fn (array $row) => $row['lot']->product_id)
            ->map(fn (Collection $rows): array => [
                'product' => $rows->first()['lot']->product,
                'unpaid_quantity' => $rows->reduce(fn (string $carry, array $row) => bcadd($carry, $row['unpaid_quantity'], self::QUANTITY), '0'),
                'unit_costs' => $rows->map(fn (array $row) => (string) $row['lot']->unit_cost)->unique()->values()->all(),
                'pending' => $rows->reduce(fn (string $carry, array $row) => bcadd($carry, $row['pending'], self::MONEY), '0.00'),
            ])
            ->values();
    }

    /**
     * Consignment product of the supplier still in stock, valued at cost: not owed yet.
     *
     * @return array{value: string, quantity: string}
     */
    public function unsold(Supplier $supplier): array
    {
        return Lot::query()
            ->where('owner_supplier_id', $supplier->id)
            ->withSum('stocks', 'quantity')
            ->get()
            ->reduce(fn (array $carry, Lot $lot) => [
                'value' => bcadd($carry['value'], $this->roundHalfUp(bcmul($this->decimal($lot->stocks_sum_quantity, self::QUANTITY), $lot->unit_cost ?? '0', 8), self::MONEY), self::MONEY),
                'quantity' => bcadd($carry['quantity'], $this->decimal($lot->stocks_sum_quantity, self::QUANTITY), self::QUANTITY),
            ], ['value' => '0.00', 'quantity' => '0.000']);
    }

    /**
     * How a payment is spread over the pending lots. Without amounts per product it
     * settles the oldest debt first; with them, each product's amount settles that
     * product's oldest lots first. The amounts must add up to the payment.
     *
     * @param  array<int, string>|null  $productAmounts  amount by product id.
     * @return list<array{lot: Lot, quantity: string, amount: string}>
     *
     * @throws ValidationException
     */
    public function plan(Supplier $supplier, string $amount, ?array $productAmounts = null): array
    {
        $pendingLots = $this->lots($supplier)->filter(fn (array $row) => bccomp($row['pending'], '0', self::MONEY) === 1)->values();
        $totalPending = $pendingLots->reduce(fn (string $carry, array $row) => bcadd($carry, $row['pending'], self::MONEY), '0');

        if (bccomp($amount, $totalPending, self::MONEY) === 1) {
            throw ValidationException::withMessages([
                'amount' => __('A :supplier solo se le deben $:pending.', ['supplier' => $supplier->name, 'pending' => number_format((float) $totalPending, 2)]),
            ]);
        }

        if ($productAmounts === null) {
            return $this->spread($pendingLots, $amount);
        }

        $productAmounts = array_filter($productAmounts, fn ($value) => is_numeric($value) && $value > 0);
        $sum = array_reduce($productAmounts, fn (string $carry, $value) => bcadd($carry, (string) $value, self::MONEY), '0');

        if (bccomp($sum, $amount, self::MONEY) !== 0) {
            throw ValidationException::withMessages(['product_amounts' => __('Lo repartido ($:sum) no coincide con el pago ($:amount).', [
                'sum' => number_format((float) $sum, 2),
                'amount' => number_format((float) $amount, 2),
            ])]);
        }

        $plan = [];

        foreach ($productAmounts as $productId => $productAmount) {
            $productLots = $pendingLots->filter(fn (array $row) => $row['lot']->product_id === (int) $productId)->values();
            $productPending = $productLots->reduce(fn (string $carry, array $row) => bcadd($carry, $row['pending'], self::MONEY), '0');

            if (bccomp((string) $productAmount, $productPending, self::MONEY) === 1) {
                throw ValidationException::withMessages(['product_amounts' => __('De :product solo se deben $:pending.', [
                    'product' => $productLots->first()['lot']->product->name ?? __('ese producto'),
                    'pending' => number_format((float) $productPending, 2),
                ])]);
            }

            array_push($plan, ...$this->spread($productLots, (string) $productAmount));
        }

        return $plan;
    }

    /**
     * @param  Collection<int, array{lot: Lot, unpaid_quantity: string, pending: string}>  $rows
     * @return list<array{lot: Lot, quantity: string, amount: string}>
     */
    private function spread(Collection $rows, string $amount): array
    {
        $remaining = bcadd($amount, '0', self::MONEY);
        $plan = [];

        foreach ($rows as $row) {
            if (bccomp($remaining, '0', self::MONEY) <= 0) {
                break;
            }

            $settles = bccomp($remaining, $row['pending'], self::MONEY) >= 0;
            $taken = $settles ? $row['pending'] : $remaining;
            $quantity = $settles || bccomp($row['lot']->unit_cost ?? '0', '0', self::MONEY) === 0
                ? $row['unpaid_quantity']
                : $this->roundHalfUp(bcdiv($taken, $row['lot']->unit_cost, 8), self::QUANTITY);

            $plan[] = ['lot' => $row['lot'], 'quantity' => $quantity, 'amount' => $taken];
            $remaining = bcsub($remaining, $taken, self::MONEY);
        }

        return $plan;
    }
}
