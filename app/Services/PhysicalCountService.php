<?php

namespace App\Services;

use App\Models\AdjustmentReason;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationType;
use App\Models\PhysicalCount;
use App\Models\PhysicalCountStatus;
use App\Models\Product;
use App\Models\Stock;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Physical count of a whole warehouse (Task 10, docs/diseno/ConteoFisico.html).
 * It can be saved and continued later; applying it creates one adjustment
 * operation with a movement for each counted product that differs. What was not
 * counted is left alone, and differences are taken against the stock at the
 * moment of applying, so sales made while counting are not adjusted twice.
 */
class PhysicalCountService
{
    private const SCALE = 3;

    public function __construct(private InventoryService $inventory) {}

    /**
     * Start counting a warehouse, listing every active product it holds or has held.
     */
    public function start(User $user, Warehouse $warehouse, ?string $note = null): PhysicalCount
    {
        return DB::transaction(function () use ($user, $warehouse, $note): PhysicalCount {
            $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($warehouse->id);

            if ($this->inProgress($warehouse) !== null) {
                throw ValidationException::withMessages([
                    'warehouse_id' => __('Ya hay un conteo en curso en :warehouse; termínalo o cancélalo primero.', ['warehouse' => $warehouse->name]),
                ]);
            }

            $count = PhysicalCount::create([
                'warehouse_id' => $warehouse->id,
                'started_by_id' => $user->id,
                'started_at' => now(),
                'status' => PhysicalCountStatus::InProgress,
                'note' => $note,
            ]);

            $quantities = Stock::query()
                ->where('warehouse_id', $warehouse->id)
                ->whereHas('product', fn ($products) => $products->where('is_active', true))
                ->groupBy('product_id')
                ->selectRaw('product_id, SUM(quantity) as quantity')
                ->pluck('quantity', 'product_id');

            foreach ($quantities as $productId => $quantity) {
                $count->items()->create([
                    'product_id' => $productId,
                    'system_quantity' => bcadd((string) $quantity, '0', self::SCALE),
                ]);
            }

            return $count;
        });
    }

    public function inProgress(Warehouse $warehouse): ?PhysicalCount
    {
        return PhysicalCount::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('status', PhysicalCountStatus::InProgress)
            ->first();
    }

    /**
     * Write down how many were counted. A product that was not on the list (found
     * on the shelf) is added with what the system has now. Null clears the count.
     */
    public function record(PhysicalCount $count, Product $product, ?string $countedQuantity): void
    {
        if ($countedQuantity !== null && (! is_numeric($countedQuantity) || $countedQuantity < 0)) {
            throw ValidationException::withMessages(['counted_quantity' => __('La cantidad contada no puede ser negativa.')]);
        }

        DB::transaction(function () use ($count, $product, $countedQuantity): void {
            $count = $this->lockInProgress($count);

            $count->items()->firstOrCreate(
                ['product_id' => $product->id],
                ['system_quantity' => $this->currentStock($product, $count->warehouse_id)],
            )->update(['counted_quantity' => $countedQuantity === null ? null : bcadd($countedQuantity, '0', self::SCALE)]);
        });
    }

    /**
     * Adjust every counted product to what was counted. Returns the count, applied.
     */
    public function apply(User $user, PhysicalCount $count): PhysicalCount
    {
        return DB::transaction(function () use ($user, $count): PhysicalCount {
            $count = $this->lockInProgress($count);
            $count->load(['warehouse', 'items.product']);
            $counted = $count->items->whereNotNull('counted_quantity');

            if ($counted->isEmpty()) {
                throw ValidationException::withMessages(['count' => __('Todavía no se ha contado ningún producto.')]);
            }

            $differing = $counted->filter(fn ($item) => bccomp($item->counted_quantity, $this->currentStock($item->product, $count->warehouse_id), self::SCALE) !== 0);
            $operation = null;

            if ($differing->isNotEmpty()) {
                $operation = InventoryOperation::create([
                    'type' => InventoryOperationType::Adjustment,
                    'user_id' => $user->id,
                    'reason' => AdjustmentReason::PhysicalCount->value,
                    'reference' => __('Conteo físico :date', ['date' => $count->started_at->translatedFormat('j M')]),
                    'note' => $count->note,
                    'occurred_at' => now(),
                ]);

                foreach ($differing as $item) {
                    $this->inventory->adjustWithin($operation, $item->product, $count->warehouse, $item->counted_quantity);
                }
            }

            $count->update([
                'status' => PhysicalCountStatus::Applied,
                'applied_by_id' => $user->id,
                'applied_at' => now(),
                'inventory_operation_id' => $operation?->id,
            ]);

            return $count;
        });
    }

    public function cancel(PhysicalCount $count): PhysicalCount
    {
        return DB::transaction(function () use ($count): PhysicalCount {
            $count = $this->lockInProgress($count);
            $count->update(['status' => PhysicalCountStatus::Canceled]);

            return $count;
        });
    }

    public function currentStock(Product $product, int $warehouseId): string
    {
        return bcadd((string) (Stock::query()->where('product_id', $product->id)->where('warehouse_id', $warehouseId)->sum('quantity') ?: '0'), '0', self::SCALE);
    }

    private function lockInProgress(PhysicalCount $count): PhysicalCount
    {
        $count = PhysicalCount::query()->lockForUpdate()->findOrFail($count->id);

        if (! $count->isInProgress()) {
            throw ValidationException::withMessages(['count' => __('Este conteo ya se aplicó o se canceló.')]);
        }

        return $count;
    }
}
