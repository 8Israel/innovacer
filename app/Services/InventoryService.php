<?php

namespace App\Services;

use App\Models\AdjustmentReason;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationType;
use App\Models\LoanEntry;
use App\Models\Lot;
use App\Models\Product;
use App\Models\SaleItem;
use App\Models\Stock;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves stock and writes the kardex: every movement records the product's total
 * in the warehouse before and after it. Stock lives in lots, consumed oldest first
 * (FIFO). Stock rows are locked with lockForUpdate(); the public operations open
 * their own transaction, while removeFifo() and restockReturn() run inside the caller's.
 *
 * Ownership: a lot belongs to its owner_supplier_id (consignment) or to the business,
 * except in a warehouse with a lender (Francisco), where everything is the lender's.
 * Taking product out of a lender's warehouse creates an in-kind loan.
 */
class InventoryService
{
    private const SCALE = 3;

    /**
     * Receive merchandise. Each item becomes a lot with its cost: owned by the
     * supplier when it comes on consignment, by the business when bought.
     *
     * @param  list<array{product_id: int, quantity: string, unit_cost?: string|null}>  $items
     */
    public function receive(
        User $user,
        Supplier $supplier,
        Warehouse $warehouse,
        array $items,
        bool $consignment,
        ?string $reference = null,
        ?string $note = null,
        ?CarbonInterface $receivedAt = null,
    ): InventoryOperation {
        $isLoanWarehouse = $warehouse->isLoanWarehouse();

        if ($isLoanWarehouse && $warehouse->lender_id !== $supplier->id) {
            throw ValidationException::withMessages([
                'supplier_id' => __('A :warehouse solo entra producto de su prestamista.', ['warehouse' => $warehouse->name]),
            ]);
        }

        $lines = $this->validateItems($items, requireCost: ! $isLoanWarehouse);
        $receivedAt ??= now();

        return DB::transaction(function () use ($user, $supplier, $warehouse, $lines, $consignment, $isLoanWarehouse, $reference, $note, $receivedAt): InventoryOperation {
            $operation = InventoryOperation::create([
                'type' => InventoryOperationType::Entry,
                'user_id' => $user->id,
                'supplier_id' => $supplier->id,
                'reference' => $reference,
                'note' => $note,
                'occurred_at' => $receivedAt,
            ]);

            foreach ($lines as [$product, $quantity, $unitCost]) {
                $lot = Lot::create([
                    'product_id' => $product->id,
                    'supplier_id' => $supplier->id,
                    'owner_supplier_id' => $consignment && ! $isLoanWarehouse ? $supplier->id : null,
                    'inventory_operation_id' => $operation->id,
                    'unit_cost' => $unitCost ?? $product->reference_cost,
                    'quantity_received' => $quantity,
                    'received_at' => $receivedAt,
                ]);

                $this->putIntoLots($operation, $product, $warehouse, [['lot_id' => $lot->id, 'quantity' => $quantity]]);
            }

            return $operation;
        });
    }

    /**
     * Move product between warehouses in one operation. The same lots move (FIFO),
     * keeping their owner. Taking from a lender's warehouse records a loan.
     *
     * @param  list<array{product_id: int, quantity: string}>  $items
     */
    public function transfer(User $user, Warehouse $from, Warehouse $to, array $items, string $note): InventoryOperation
    {
        if (blank($note)) {
            throw ValidationException::withMessages(['note' => __('El traspaso necesita una nota.')]);
        }

        if ($from->is($to)) {
            throw ValidationException::withMessages(['to_warehouse_id' => __('El almacén de origen y el de destino deben ser distintos.')]);
        }

        if ($to->isLoanWarehouse()) {
            throw ValidationException::withMessages([
                'to_warehouse_id' => __('Para regresar producto a :warehouse registra una reposición en Préstamos.', ['warehouse' => $to->name]),
            ]);
        }

        $lines = $this->validateItems($items);

        return DB::transaction(function () use ($user, $from, $to, $lines, $note): InventoryOperation {
            $operation = InventoryOperation::create([
                'type' => InventoryOperationType::Transfer,
                'user_id' => $user->id,
                'note' => $note,
                'occurred_at' => now(),
            ]);

            foreach ($lines as [$product, $quantity]) {
                $allocations = $this->takeFifo($operation, $product, $from, $quantity);
                $this->putIntoLots($operation, $product, $to, $allocations);

                if ($from->isLoanWarehouse()) {
                    LoanEntry::create([
                        'lender_id' => $from->lender_id,
                        'product_id' => $product->id,
                        'inventory_operation_id' => $operation->id,
                        'quantity' => $quantity,
                        'note' => $note,
                    ]);
                }
            }

            return $operation;
        });
    }

    /**
     * Repay (part of) a loan with the same product, moving the business's own
     * lots from a warehouse back to the lender's warehouse.
     */
    public function repayLoan(User $user, LoanEntry $loan, string $quantity, Warehouse $from, ?string $note = null): InventoryOperation
    {
        $quantity = $this->quantity($quantity, 'quantity');

        if ($from->isLoanWarehouse()) {
            throw ValidationException::withMessages([
                'from_warehouse_id' => __('La reposición sale de un almacén propio.'),
            ]);
        }

        return DB::transaction(function () use ($user, $loan, $quantity, $from, $note): InventoryOperation {
            $loan = LoanEntry::query()->with(['product', 'lender'])->lockForUpdate()->findOrFail($loan->id);

            if ($loan->repays_loan_entry_id !== null || bccomp($loan->quantity, '0', self::SCALE) <= 0) {
                throw ValidationException::withMessages(['loan' => __('Solo se pueden reponer préstamos.')]);
            }

            $pending = $this->pendingQuantity($loan);

            if (bccomp($quantity, $pending, self::SCALE) === 1) {
                throw ValidationException::withMessages([
                    'quantity' => __('A este préstamo solo le faltan :pending por reponer.', ['pending' => $this->format($pending)]),
                ]);
            }

            $lenderWarehouse = Warehouse::query()->where('lender_id', $loan->lender_id)->where('is_active', true)->first()
                ?? throw ValidationException::withMessages(['loan' => __(':lender no tiene un almacén activo.', ['lender' => $loan->lender->name])]);

            $operation = InventoryOperation::create([
                'type' => InventoryOperationType::Transfer,
                'user_id' => $user->id,
                'note' => $note ?? __('Reposición a :lender', ['lender' => $loan->lender->name]),
                'occurred_at' => now(),
            ]);

            $allocations = $this->takeFifo($operation, $loan->product, $from, $quantity, ownStockOnly: true);
            $this->putIntoLots($operation, $loan->product, $lenderWarehouse, $allocations);

            LoanEntry::create([
                'lender_id' => $loan->lender_id,
                'product_id' => $loan->product_id,
                'inventory_operation_id' => $operation->id,
                'repays_loan_entry_id' => $loan->id,
                'quantity' => bcsub('0', $quantity, self::SCALE),
                'note' => $note,
            ]);

            return $operation;
        });
    }

    /**
     * Quantity of a loan still to be repaid.
     */
    public function pendingQuantity(LoanEntry $loan): string
    {
        $repaid = $loan->repayments()->sum('quantity');

        return bcadd($loan->quantity, $this->normalize($repaid), self::SCALE);
    }

    /**
     * Loans not fully repaid yet, oldest first, each with its pending_quantity.
     *
     * @return Collection<int, LoanEntry>
     */
    public function pendingLoans(?Supplier $lender = null): Collection
    {
        return LoanEntry::query()
            ->with(['product', 'lender'])
            ->whereNull('repays_loan_entry_id')
            ->where('quantity', '>', 0)
            ->when($lender, fn (Builder $query) => $query->where('lender_id', $lender->id))
            ->withSum('repayments', 'quantity')
            ->oldest('id')
            ->get()
            ->each(fn (LoanEntry $loan) => $loan->setAttribute(
                'pending_quantity',
                bcadd($loan->quantity, $this->normalize($loan->repayments_sum_quantity), self::SCALE),
            ))
            ->filter(fn (LoanEntry $loan) => bccomp($loan->pending_quantity, '0', self::SCALE) === 1)
            ->values();
    }

    /**
     * Take product out for something that is not a sale (samples, experiments),
     * recording why and who took it.
     *
     * @param  list<array{product_id: int, quantity: string}>  $items
     */
    public function exit(User $user, Warehouse $warehouse, array $items, string $reason, string $responsible, ?string $note = null): InventoryOperation
    {
        if (blank($reason) || blank($responsible)) {
            throw ValidationException::withMessages([
                'reason' => __('La salida necesita un motivo y quién se lo llevó.'),
            ]);
        }

        if ($warehouse->isLoanWarehouse()) {
            throw ValidationException::withMessages([
                'warehouse_id' => __('De :warehouse solo se saca producto con un traspaso, para que quede el préstamo.', ['warehouse' => $warehouse->name]),
            ]);
        }

        $lines = $this->validateItems($items);

        return DB::transaction(function () use ($user, $warehouse, $lines, $reason, $responsible, $note): InventoryOperation {
            $operation = InventoryOperation::create([
                'type' => InventoryOperationType::Exit,
                'user_id' => $user->id,
                'reason' => $reason,
                'responsible' => $responsible,
                'note' => $note,
                'occurred_at' => now(),
            ]);

            foreach ($lines as [$product, $quantity]) {
                $this->takeFifo($operation, $product, $warehouse, $quantity);
            }

            return $operation;
        });
    }

    /**
     * Adjust a product's stock in a warehouse to a physical count. A shortage leaves
     * oldest lots first; a surplus enters as a new lot owned by the business at the
     * product's reference cost.
     */
    public function adjust(User $user, Product $product, Warehouse $warehouse, string $countedQuantity, AdjustmentReason $reason, ?string $note = null): InventoryOperation
    {
        $countedQuantity = $this->quantity($countedQuantity, 'counted_quantity', allowZero: true);

        return DB::transaction(function () use ($user, $product, $warehouse, $countedQuantity, $reason, $note): InventoryOperation {
            $systemQuantity = $this->total($this->lockStocks($product, $warehouse));
            $difference = bcsub($countedQuantity, $systemQuantity, self::SCALE);
            $sign = bccomp($difference, '0', self::SCALE);

            if ($sign === 0) {
                throw ValidationException::withMessages([
                    'counted_quantity' => __('El conteo coincide con el sistema; no hay nada que ajustar.'),
                ]);
            }

            if (($sign < 0 && ! $reason->allowsShortage()) || ($sign > 0 && ! $reason->allowsSurplus())) {
                throw ValidationException::withMessages([
                    'reason' => $sign < 0
                        ? __('Un sobrante no puede bajar la existencia.')
                        : __('Ese motivo solo aplica cuando faltan piezas.'),
                ]);
            }

            $operation = InventoryOperation::create([
                'type' => InventoryOperationType::Adjustment,
                'user_id' => $user->id,
                'reason' => $reason->value,
                'note' => $note,
                'occurred_at' => now(),
            ]);

            if ($sign < 0) {
                $this->takeFifo($operation, $product, $warehouse, bcsub('0', $difference, self::SCALE));

                return $operation;
            }

            $lot = Lot::create([
                'product_id' => $product->id,
                'inventory_operation_id' => $operation->id,
                'unit_cost' => $product->reference_cost,
                'quantity_received' => $difference,
                'received_at' => now(),
            ]);

            $this->putIntoLots($operation, $product, $warehouse, [['lot_id' => $lot->id, 'quantity' => $difference]]);

            return $operation;
        });
    }

    /**
     * Kardex of a product, newest first, optionally narrowed to a warehouse and dates.
     *
     * @return Builder<InventoryMovement>
     */
    public function kardex(Product $product, ?Warehouse $warehouse = null, ?CarbonInterface $from = null, ?CarbonInterface $until = null): Builder
    {
        return InventoryMovement::query()
            ->with(['inventoryOperation.user', 'warehouse', 'lot.supplier'])
            ->where('product_id', $product->id)
            ->when($warehouse, fn (Builder $query) => $query->where('warehouse_id', $warehouse->id))
            ->when($from || $until, fn (Builder $query) => $query->whereHas('inventoryOperation', fn (Builder $operation) => $operation
                ->when($from, fn (Builder $q) => $q->where('occurred_at', '>=', $from))
                ->when($until, fn (Builder $q) => $q->where('occurred_at', '<=', $until))))
            ->orderByDesc('id');
    }

    /**
     * Take a quantity of a product out of a warehouse, oldest lots first, writing one
     * kardex line per lot. Runs inside the caller's transaction.
     *
     * @throws ValidationException when the warehouse does not hold enough stock.
     */
    public function removeFifo(InventoryOperation $operation, Product $product, Warehouse $warehouse, string $quantity, ?SaleItem $saleItem = null): void
    {
        $this->takeFifo($operation, $product, $warehouse, $quantity, $saleItem);
    }

    /**
     * Put returned product back into the lots its sale item took it from, latest
     * consumed lot first. Runs inside the caller's transaction.
     */
    public function restockReturn(InventoryOperation $operation, SaleItem $saleItem, string $quantity, Warehouse $warehouse): void
    {
        $returnableByLot = InventoryMovement::query()
            ->where('sale_item_id', $saleItem->id)
            ->where('warehouse_id', $warehouse->id)
            ->selectRaw('lot_id, MAX(id) as last_movement_id, SUM(quantity) as net_quantity')
            ->groupBy('lot_id')
            ->orderByDesc('last_movement_id')
            ->get();

        $allocations = [];
        $remaining = $quantity;

        foreach ($returnableByLot as $row) {
            $returnable = bcsub('0', $this->normalize($row->net_quantity), self::SCALE);

            if (bccomp($remaining, '0', self::SCALE) <= 0 || bccomp($returnable, '0', self::SCALE) <= 0) {
                continue;
            }

            $taken = bccomp($returnable, $remaining, self::SCALE) < 0 ? $returnable : $remaining;
            $allocations[] = ['lot_id' => $row->lot_id, 'quantity' => $taken];
            $remaining = bcsub($remaining, $taken, self::SCALE);
        }

        if (bccomp($remaining, '0', self::SCALE) === 1) {
            throw ValidationException::withMessages([
                'items' => __('No se puede regresar más :product del que salió en la venta.', ['product' => $saleItem->product->name]),
            ]);
        }

        $this->putIntoLots($operation, $saleItem->product, $warehouse, $allocations, $saleItem);
    }

    /**
     * @return list<array{lot_id: int, quantity: string}> what was taken from each lot.
     */
    private function takeFifo(
        InventoryOperation $operation,
        Product $product,
        Warehouse $warehouse,
        string $quantity,
        ?SaleItem $saleItem = null,
        bool $ownStockOnly = false,
    ): array {
        $stocks = $this->lockStocks($product, $warehouse);
        $stockBefore = $this->total($stocks);
        $eligible = $ownStockOnly ? $stocks->filter(fn (Stock $stock) => $stock->lot->owner_supplier_id === null) : $stocks;
        $available = $this->total($eligible);

        if (bccomp($available, $quantity, self::SCALE) < 0) {
            throw ValidationException::withMessages([
                'items' => $ownStockOnly
                    ? __('No hay suficiente :product propio en :warehouse: hay :available y se necesitan :quantity.', [
                        'product' => $product->name, 'warehouse' => $warehouse->name,
                        'available' => $this->format($available), 'quantity' => $this->format($quantity),
                    ])
                    : __('No hay suficiente existencia de :product en :warehouse: hay :available y se necesitan :quantity.', [
                        'product' => $product->name, 'warehouse' => $warehouse->name,
                        'available' => $this->format($available), 'quantity' => $this->format($quantity),
                    ]),
            ]);
        }

        $allocations = [];
        $remaining = $quantity;

        foreach ($eligible as $stock) {
            if (bccomp($remaining, '0', self::SCALE) <= 0) {
                break;
            }

            $taken = bccomp($stock->quantity, $remaining, self::SCALE) < 0 ? $stock->quantity : $remaining;
            $stockAfter = bcsub($stockBefore, $taken, self::SCALE);

            $stock->update(['quantity' => bcsub($stock->quantity, $taken, self::SCALE)]);

            $operation->movements()->create([
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'lot_id' => $stock->lot_id,
                'sale_item_id' => $saleItem?->id,
                'quantity' => bcsub('0', $taken, self::SCALE),
                'stock_before' => $stockBefore,
                'stock_after' => $stockAfter,
            ]);

            $allocations[] = ['lot_id' => $stock->lot_id, 'quantity' => $taken];
            $remaining = bcsub($remaining, $taken, self::SCALE);
            $stockBefore = $stockAfter;
        }

        return $allocations;
    }

    /**
     * @param  list<array{lot_id: int, quantity: string}>  $allocations
     */
    private function putIntoLots(InventoryOperation $operation, Product $product, Warehouse $warehouse, array $allocations, ?SaleItem $saleItem = null): void
    {
        $stocks = $this->lockStocks($product, $warehouse, includeEmpty: true)->keyBy('lot_id');
        $stockBefore = $this->total($stocks);

        foreach ($allocations as ['lot_id' => $lotId, 'quantity' => $quantity]) {
            $stock = $stocks->get($lotId) ?? new Stock([
                'warehouse_id' => $warehouse->id,
                'lot_id' => $lotId,
                'product_id' => $product->id,
                'quantity' => '0',
            ]);
            $stock->quantity = bcadd($stock->quantity, $quantity, self::SCALE);
            $stock->save();
            $stocks->put($lotId, $stock);

            $stockAfter = bcadd($stockBefore, $quantity, self::SCALE);

            $operation->movements()->create([
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'lot_id' => $lotId,
                'sale_item_id' => $saleItem?->id,
                'quantity' => $quantity,
                'stock_before' => $stockBefore,
                'stock_after' => $stockAfter,
            ]);

            $stockBefore = $stockAfter;
        }
    }

    /**
     * Stock rows of a product in a warehouse, oldest lot first, locked for update.
     *
     * @return Collection<int, Stock>
     */
    private function lockStocks(Product $product, Warehouse $warehouse, bool $includeEmpty = false): Collection
    {
        return Stock::query()
            ->select('stocks.*')
            ->join('lots', 'lots.id', '=', 'stocks.lot_id')
            ->with('lot')
            ->where('stocks.product_id', $product->id)
            ->where('stocks.warehouse_id', $warehouse->id)
            ->when(! $includeEmpty, fn (Builder $query) => $query->where('stocks.quantity', '>', 0))
            ->orderBy('lots.received_at')
            ->orderBy('lots.id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  Collection<int, Stock>  $stocks
     */
    private function total(Collection $stocks): string
    {
        return $stocks->reduce(fn (string $carry, Stock $stock) => bcadd($carry, $stock->quantity, self::SCALE), '0');
    }

    /**
     * @param  list<array{product_id: int, quantity: string, unit_cost?: string|null}>  $items
     * @return list<array{0: Product, 1: string, 2: string|null}>
     */
    private function validateItems(array $items, bool $requireCost = false): array
    {
        if ($items === []) {
            throw ValidationException::withMessages(['items' => __('Agrega al menos un producto.')]);
        }

        $products = Product::query()->findMany(array_column($items, 'product_id'))->keyBy('id');

        return array_map(function (array $item) use ($products, $requireCost): array {
            $product = $products->get($item['product_id'] ?? null)
                ?? throw ValidationException::withMessages(['items' => __('Uno de los productos no existe en el catálogo.')]);

            $unitCost = $item['unit_cost'] ?? null;

            if ($requireCost && (! is_numeric($unitCost) || $unitCost < 0)) {
                throw ValidationException::withMessages([
                    'items' => __('Indica el costo unitario de :product.', ['product' => $product->name]),
                ]);
            }

            return [
                $product,
                $this->quantity((string) ($item['quantity'] ?? ''), 'items'),
                is_numeric($unitCost) ? bcadd((string) $unitCost, '0', 2) : null,
            ];
        }, array_values($items));
    }

    /**
     * Validate a quantity (> 0, or ≥ 0 when allowed) and normalize it to 3 decimals.
     */
    private function quantity(string $value, string $field, bool $allowZero = false): string
    {
        $isValid = is_numeric($value) && ($allowZero ? $value >= 0 : $value > 0);

        if (! $isValid) {
            throw ValidationException::withMessages([
                $field => $allowZero
                    ? __('La cantidad debe ser un número mayor o igual a cero.')
                    : __('La cantidad debe ser un número mayor a cero.'),
            ]);
        }

        return bcadd($value, '0', self::SCALE);
    }

    /**
     * Turn a database aggregate (string, int, float or null) into a 3-decimal string.
     */
    private function normalize(mixed $value): string
    {
        if ($value === null) {
            return '0';
        }

        return is_float($value) ? sprintf('%.3f', $value) : bcadd((string) $value, '0', self::SCALE);
    }

    /**
     * Drop insignificant trailing zeros for messages: "3.000" becomes "3".
     */
    private function format(string $quantity): string
    {
        if (! str_contains($quantity, '.')) {
            return $quantity;
        }

        return rtrim(rtrim($quantity, '0'), '.') ?: '0';
    }
}
