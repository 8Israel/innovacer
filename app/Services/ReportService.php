<?php

namespace App\Services;

use App\Concerns\RoundsDecimals;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\SaleItem;
use App\Models\SaleStatus;
use App\Models\Stock;
use App\Models\StockMinimum;
use App\Models\Supplier;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The owner's reports (docs/diseno/Reportes.html, RF-36 to RF-39). Sales count what
 * was charged (taxes included) less what came back in returns; profit is the sale
 * without taxes less the cost of the lots it came from. Cancelled sales are left out.
 */
class ReportService
{
    use RoundsDecimals;

    private const MONEY = 2;

    private const QUANTITY = 3;

    public function __construct(
        private CustomerBalanceService $customerBalances,
        private SupplierDebtService $supplierDebts,
        private SettingsService $settings,
    ) {}

    /**
     * Every item sold in the period, net of returns, with its cost and owner.
     *
     * @return Collection<int, array{item: SaleItem, sold_at: CarbonInterface, folio: string, customer: string|null, destination: string|null, quantity: string, sales: string, net: string, cost: string, profit: string, missing_cost: bool, owner: string}>
     */
    public function lines(CarbonInterface $from, CarbonInterface $until): Collection
    {
        $items = SaleItem::query()
            ->with(['product', 'sale.customer', 'sale.customerDestination'])
            ->whereHas('sale', fn ($sales) => $sales->where('status', SaleStatus::Completed)->whereBetween('sold_at', [$from->copy()->startOfDay(), $until->copy()->endOfDay()]))
            ->withSum('customerReturnItems as returned_quantity', 'quantity')
            ->withSum('customerReturnItems as returned_amount', 'refund_amount')
            ->get();

        $movements = InventoryMovement::query()
            ->with(['lot.ownerSupplier', 'warehouse.lender'])
            ->whereIn('sale_item_id', $items->pluck('id'))
            ->get()
            ->groupBy('sale_item_id');

        $business = $this->settings->get('business_name', config('app.name'));

        return $items->map(function (SaleItem $item) use ($movements, $business): array {
            $itemMovements = $movements->get($item->id, collect());
            $returned = $this->decimal($item->returned_quantity, self::QUANTITY);
            $kept = bcsub($item->quantity, $returned, self::QUANTITY);
            $netBeforeReturns = bcsub($item->subtotal, $item->discount_amount, self::MONEY);
            $net = bccomp($item->quantity, '0', self::QUANTITY) === 1
                ? $this->roundHalfUp(bcdiv(bcmul($netBeforeReturns, $kept, 8), $item->quantity, 8), self::MONEY)
                : '0.00';
            $cost = $itemMovements->reduce(
                fn (string $carry, InventoryMovement $movement) => bcsub($carry, bcmul($movement->quantity, $movement->lot->unit_cost ?? '0', 8), 8),
                '0',
            );
            $cost = $this->roundHalfUp($cost, self::MONEY);

            return [
                'item' => $item,
                'sold_at' => $item->sale->sold_at,
                'folio' => $item->sale->folio,
                'customer' => $item->sale->customer?->name,
                'destination' => $item->sale->customerDestination?->name,
                'quantity' => $kept,
                'sales' => bcsub($item->total, $this->decimal($item->returned_amount, self::MONEY), self::MONEY),
                'net' => $net,
                'cost' => $cost,
                'profit' => bcsub($net, $cost, self::MONEY),
                'missing_cost' => $itemMovements->contains(fn (InventoryMovement $movement) => $movement->lot->unit_cost === null),
                'owner' => $itemMovements
                    ->map(fn (InventoryMovement $movement) => $movement->warehouse->lender?->name ?? $movement->lot->ownerSupplier?->name ?? $business)
                    ->unique()
                    ->join(', ') ?: $business,
            ];
        });
    }

    /**
     * @return array{sales: string, net: string, profit: string, margin: string|null, days_with_sales: int, missing_cost: bool}
     */
    public function summary(Collection $lines): array
    {
        $net = $this->sum($lines, 'net');
        $profit = $this->sum($lines, 'profit');

        return [
            'sales' => $this->sum($lines, 'sales'),
            'net' => $net,
            'profit' => $profit,
            'margin' => $this->margin($profit, $net),
            'days_with_sales' => $lines->map(fn (array $line) => $line['sold_at']->toDateString())->unique()->count(),
            'missing_cost' => $lines->contains('missing_cost', true),
        ];
    }

    /**
     * Sales of every day of the period, including days without sales.
     *
     * @return Collection<string, string> amount by Y-m-d date.
     */
    public function salesByDay(Collection $lines, CarbonInterface $from, CarbonInterface $until): Collection
    {
        $byDay = $lines->groupBy(fn (array $line) => $line['sold_at']->toDateString());
        $days = collect();

        for ($day = $from->copy()->startOfDay(); $day->lte($until); $day = $day->addDay()) {
            $days[$day->toDateString()] = $this->sum($byDay->get($day->toDateString(), collect()), 'sales');
        }

        return $days;
    }

    /**
     * "What went to each place?": sales by customer destination, those without one together.
     *
     * @return Collection<int, array{destination: string|null, sales: string}>
     */
    public function salesByDestination(Collection $lines): Collection
    {
        return $lines->groupBy(fn (array $line) => $line['destination'] ?? '')
            ->map(fn (Collection $group, string $destination) => ['destination' => $destination === '' ? null : $destination, 'sales' => $this->sum($group, 'sales')])
            ->sortByDesc(fn (array $row) => (float) $row['sales'])
            ->values();
    }

    /**
     * Best sellers by sales, with their profit.
     *
     * @return Collection<int, array{product: Product, owner: string, quantity: string, sales: string, profit: string, margin: string|null, missing_cost: bool}>
     */
    public function products(Collection $lines): Collection
    {
        return $lines->groupBy(fn (array $line) => $line['item']->product_id)
            ->map(function (Collection $group): array {
                $net = $this->sum($group, 'net');
                $profit = $this->sum($group, 'profit');

                return [
                    'product' => $group->first()['item']->product,
                    'owner' => $group->pluck('owner')->flatMap(fn (string $owner) => explode(', ', $owner))->unique()->join(', '),
                    'quantity' => $group->reduce(fn (string $carry, array $line) => bcadd($carry, $line['quantity'], self::QUANTITY), '0'),
                    'sales' => $this->sum($group, 'sales'),
                    'profit' => $profit,
                    'margin' => $this->margin($profit, $net),
                    'missing_cost' => $group->contains('missing_cost', true),
                ];
            })
            ->sortByDesc(fn (array $row) => (float) $row['sales'])
            ->values();
    }

    /**
     * Stock valued at lot cost, by whose it is: the business, a consignment
     * supplier, or the lender of a warehouse.
     *
     * @return Collection<int, array{owner: string, own: bool, value: string, quantity: string}>
     */
    public function inventoryAtCost(): Collection
    {
        $business = $this->settings->get('business_name', config('app.name'));

        return Stock::query()
            ->with(['lot.ownerSupplier', 'warehouse.lender'])
            ->where('quantity', '>', 0)
            ->get()
            ->groupBy(fn (Stock $stock) => $stock->warehouse->lender?->name ?? $stock->lot->ownerSupplier?->name ?? '')
            ->map(fn (Collection $stocks, string $owner) => [
                'owner' => $owner === '' ? $business : $owner,
                'own' => $owner === '',
                'value' => $stocks->reduce(fn (string $carry, Stock $stock) => bcadd($carry, $this->roundHalfUp(bcmul($stock->quantity, $stock->lot->unit_cost ?? '0', 8), self::MONEY), self::MONEY), '0.00'),
                'quantity' => $stocks->reduce(fn (string $carry, Stock $stock) => bcadd($carry, $stock->quantity, self::QUANTITY), '0'),
            ])
            ->sortBy([['own', 'desc'], fn (array $a, array $b) => (float) $b['value'] <=> (float) $a['value']])
            ->values();
    }

    /**
     * Customers who owe, most first.
     *
     * @return Collection<int, array{customer: Customer, balance: string}>
     */
    public function receivables(): Collection
    {
        return Customer::query()->whereHas('sales')->get()
            ->map(fn (Customer $customer) => ['customer' => $customer, 'balance' => $this->customerBalances->balance($customer)])
            ->filter(fn (array $row) => bccomp($row['balance'], '0', self::MONEY) === 1)
            ->sortByDesc(fn (array $row) => (float) $row['balance'])
            ->values();
    }

    /**
     * Suppliers owed money, most first.
     *
     * @return Collection<int, array{supplier: Supplier, pending: string}>
     */
    public function payables(): Collection
    {
        return Supplier::query()->get()
            ->map(fn (Supplier $supplier) => ['supplier' => $supplier, 'pending' => $this->supplierDebts->pending($supplier)])
            ->filter(fn (array $row) => bccomp($row['pending'], '0', self::MONEY) === 1)
            ->sortByDesc(fn (array $row) => (float) $row['pending'])
            ->values();
    }

    /**
     * Products at or below their minimum in some warehouse.
     */
    public function belowMinimumCount(): int
    {
        $stock = Stock::query()->groupBy('product_id', 'warehouse_id')->selectRaw('product_id, warehouse_id, SUM(quantity) as quantity')->get()
            ->keyBy(fn ($row) => $row->product_id.'-'.$row->warehouse_id);

        return StockMinimum::query()->get()
            ->filter(fn (StockMinimum $minimum) => (float) ($stock[$minimum->product_id.'-'.$minimum->warehouse_id]->quantity ?? 0) <= (float) $minimum->min_quantity)
            ->count();
    }

    private function sum(Collection $lines, string $key): string
    {
        return $lines->reduce(fn (string $carry, array $line) => bcadd($carry, $line[$key], self::MONEY), '0.00');
    }

    private function margin(string $profit, string $net): ?string
    {
        return bccomp($net, '0', self::MONEY) === 1 ? $this->roundHalfUp(bcmul(bcdiv($profit, $net, 8), '100', 8), 1) : null;
    }
}
