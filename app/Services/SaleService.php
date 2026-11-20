<?php

namespace App\Services;

use App\Concerns\RoundsDecimals;
use App\Models\Customer;
use App\Models\CustomerDestination;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationType;
use App\Models\PaymentMethod;
use App\Models\PriceType;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleStatus;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Holds, charges and discards sales.
 *
 * Prices exclude taxes. Customer and sale discounts are chained (10 % then 5 % = 14.5 %).
 * IEPS is charged on the discounted amount and IVA on the discounted amount plus IEPS.
 * Money is handled as strings with bcmath to avoid float rounding.
 */
class SaleService
{
    use RoundsDecimals;

    private const MONEY = 2;

    private const RATE = 4;

    private const QUANTITY = 3;

    public function __construct(
        private CashSessionService $cashSessions,
        private InventoryService $inventory,
        private FolioSequence $folios,
    ) {}

    /**
     * Save a sale to be charged later. It gets no folio, payments or inventory
     * operation, and stock is not touched.
     *
     * @param  list<array{product_id: int, quantity: string, price_type?: PriceType}>  $items
     */
    public function hold(
        User $user,
        Warehouse $warehouse,
        array $items,
        ?Customer $customer = null,
        ?CustomerDestination $destination = null,
        string $discountRate = '0',
        ?string $notes = null,
        ?string $holdNote = null,
    ): Sale {
        $this->ensureSellable($warehouse);
        $this->ensureDestinationBelongsTo($destination, $customer);
        $discountRate = $this->rate($discountRate);
        $lines = $this->validateItems($items);

        return DB::transaction(function () use ($user, $warehouse, $lines, $customer, $destination, $discountRate, $notes, $holdNote): Sale {
            $sale = Sale::create([
                'customer_id' => $customer?->id,
                'customer_destination_id' => $destination?->id,
                'warehouse_id' => $warehouse->id,
                'user_id' => $user->id,
                'status' => SaleStatus::Pending,
                'discount_rate' => $discountRate,
                'notes' => $notes,
                'held_at' => now(),
                'hold_note' => $holdNote,
            ]);

            $customerRate = $customer?->discount_rate ?? '0';

            foreach ($lines as [$product, $quantity, $priceType]) {
                $sale->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'price_type' => $priceType,
                    ...$this->priceLine($product, $quantity, $priceType, $customerRate, $discountRate),
                ]);
            }

            $this->refreshTotals($sale);

            return $sale;
        });
    }

    /**
     * Charge a pending sale: re-price it with current prices, take the stock out
     * (oldest lots first), record the payments in the open register and give it a folio.
     * Anything left unpaid is owed on credit by the customer.
     *
     * @param  list<array{method: PaymentMethod, amount: string, received_amount?: string|null, reference?: string|null}>  $payments
     */
    public function complete(Sale $sale, User $user, array $payments): Sale
    {
        $payments = $this->validatePayments($payments);

        return DB::transaction(function () use ($sale, $user, $payments): Sale {
            $sale = Sale::query()->lockForUpdate()->findOrFail($sale->id);

            if (! $sale->isPending()) {
                throw ValidationException::withMessages([
                    'sale' => __('Solo se pueden cobrar ventas pendientes.'),
                ]);
            }

            $session = $this->cashSessions->requireOpen();

            $sale->load(['items.product', 'customer', 'warehouse']);
            $this->ensureSellable($sale->warehouse);

            $customerRate = $sale->customer?->discount_rate ?? '0';

            foreach ($sale->items as $item) {
                $item->update($this->priceLine($item->product, $item->quantity, $item->price_type, $customerRate, $sale->discount_rate));
            }

            $this->refreshTotals($sale);
            $this->ensurePaymentsFit($sale, $payments);

            $operation = InventoryOperation::create([
                'type' => InventoryOperationType::Sale,
                'user_id' => $user->id,
                'occurred_at' => now(),
            ]);

            foreach ($sale->items as $item) {
                $this->inventory->removeFifo($operation, $item->product, $sale->warehouse, $item->quantity, $item);
            }

            foreach ($payments as $payment) {
                $sale->payments()->create([...$payment, 'cash_session_id' => $session->id]);
            }

            $folio = $this->folios->next(Sale::class, 'V');
            $operation->update(['reference' => $folio]);

            $sale->update([
                'folio' => $folio,
                'status' => SaleStatus::Completed,
                'sold_at' => now(),
                'user_id' => $user->id,
                'cash_session_id' => $session->id,
                'inventory_operation_id' => $operation->id,
            ]);

            return $sale;
        });
    }

    /**
     * Delete a pending sale and its items. Only pending sales can be discarded,
     * because they never touched stock or cash.
     */
    public function discard(Sale $sale): void
    {
        DB::transaction(function () use ($sale): void {
            $sale = Sale::query()->lockForUpdate()->findOrFail($sale->id);

            if (! $sale->isPending()) {
                throw ValidationException::withMessages([
                    'sale' => __('Solo se pueden descartar ventas pendientes.'),
                ]);
            }

            $sale->items()->delete();
            $sale->delete();
        });
    }

    /**
     * Prices, discount and taxes of one line with the product's current data.
     *
     * @return array{unit_price: string, discount_rate: string, discount_amount: string, subtotal: string, iva_rate: string, iva_amount: string, ieps_rate: string, ieps_amount: string, total: string}
     */
    private function priceLine(Product $product, string $quantity, PriceType $priceType, string $customerRate, string $saleRate): array
    {
        if (! $product->is_active) {
            throw ValidationException::withMessages([
                'items' => __('El producto :product está inactivo.', ['product' => $product->name]),
            ]);
        }

        $unitPrice = $priceType === PriceType::Wholesale ? $product->wholesale_price : $product->unit_price;

        if ($unitPrice === null) {
            throw ValidationException::withMessages([
                'items' => $priceType === PriceType::Wholesale
                    ? __('El producto :product no tiene precio de mayoreo.', ['product' => $product->name])
                    : __('El producto :product no tiene precio de venta y no se puede vender.', ['product' => $product->name]),
            ]);
        }

        $discountRate = $this->roundHalfUp(
            bcsub('1', bcmul(bcsub('1', $customerRate, self::RATE), bcsub('1', $saleRate, self::RATE), 8), 8),
            self::RATE,
        );

        $gross = $this->roundHalfUp(bcmul($quantity, $unitPrice, 8), self::MONEY);
        $discountAmount = $this->roundHalfUp(bcmul($gross, $discountRate, 8), self::MONEY);
        $net = bcsub($gross, $discountAmount, self::MONEY);

        $iepsRate = $product->ieps_rate ?? '0';
        $ieps = $this->roundHalfUp(bcmul($net, $iepsRate, 8), self::MONEY);

        $ivaRate = $product->tax_rate;
        $iva = $this->roundHalfUp(bcmul(bcadd($net, $ieps, self::MONEY), $ivaRate, 8), self::MONEY);

        return [
            'unit_price' => $unitPrice,
            'discount_rate' => $discountRate,
            'discount_amount' => $discountAmount,
            'subtotal' => $gross,
            'iva_rate' => $ivaRate,
            'iva_amount' => $iva,
            'ieps_rate' => $iepsRate,
            'ieps_amount' => $ieps,
            'total' => bcadd(bcadd($net, $ieps, self::MONEY), $iva, self::MONEY),
        ];
    }

    /**
     * Recompute the sale's totals from its items.
     */
    private function refreshTotals(Sale $sale): void
    {
        $items = $sale->items()->get();
        $sum = fn (string $column) => $items->reduce(fn (string $carry, SaleItem $item) => bcadd($carry, $item->{$column}, self::MONEY), '0');

        $sale->update([
            'subtotal' => $sum('subtotal'),
            'discount_total' => $sum('discount_amount'),
            'tax_total' => $sum('iva_amount'),
            'ieps_total' => $sum('ieps_amount'),
            'total' => $sum('total'),
        ]);
    }

    /**
     * @param  list<array{method: PaymentMethod, amount: string, received_amount?: string|null, reference?: string|null}>  $payments
     */
    private function ensurePaymentsFit(Sale $sale, array $payments): void
    {
        $paid = array_reduce($payments, fn (string $carry, array $payment) => bcadd($carry, $payment['amount'], self::MONEY), '0');

        if (bccomp($paid, $sale->total, self::MONEY) === 1) {
            throw ValidationException::withMessages([
                'payments' => __('Los pagos ($:paid) rebasan el total de la venta ($:total).', ['paid' => $paid, 'total' => $sale->total]),
            ]);
        }

        if ($sale->customer_id === null && bccomp($paid, $sale->total, self::MONEY) !== 0) {
            throw ValidationException::withMessages([
                'payments' => __('Una venta sin cliente debe pagarse completa: faltan $:missing.', [
                    'missing' => bcsub($sale->total, $paid, self::MONEY),
                ]),
            ]);
        }
    }

    /**
     * @param  list<array{method: PaymentMethod, amount: string, received_amount?: string|null, reference?: string|null}>  $payments
     * @return list<array{method: PaymentMethod, amount: string, received_amount: string|null, change_amount: string|null, reference: string|null}>
     */
    private function validatePayments(array $payments): array
    {
        return array_map(function (array $payment): array {
            $method = $payment['method'] ?? null;
            $amount = $payment['amount'] ?? null;

            if (! $method instanceof PaymentMethod || ! is_numeric($amount) || $amount <= 0) {
                throw ValidationException::withMessages([
                    'payments' => __('Cada pago necesita una forma de pago y un monto mayor a cero.'),
                ]);
            }

            $amount = bcadd((string) $amount, '0', self::MONEY);
            $receivedAmount = null;
            $changeAmount = null;

            if ($method === PaymentMethod::Cash) {
                $receivedAmount = bcadd((string) ($payment['received_amount'] ?? $amount), '0', self::MONEY);

                if (bccomp($receivedAmount, $amount, self::MONEY) < 0) {
                    throw ValidationException::withMessages([
                        'payments' => __('El efectivo recibido ($:received) no cubre el monto en efectivo ($:amount).', [
                            'received' => $receivedAmount,
                            'amount' => $amount,
                        ]),
                    ]);
                }

                $changeAmount = bcsub($receivedAmount, $amount, self::MONEY);
            }

            return [
                'method' => $method,
                'amount' => $amount,
                'received_amount' => $receivedAmount,
                'change_amount' => $changeAmount,
                'reference' => $payment['reference'] ?? null,
            ];
        }, array_values($payments));
    }

    /**
     * @param  list<array{product_id: int, quantity: string, price_type?: PriceType}>  $items
     * @return list<array{0: Product, 1: string, 2: PriceType}>
     */
    private function validateItems(array $items): array
    {
        if ($items === []) {
            throw ValidationException::withMessages(['items' => __('La venta no tiene productos.')]);
        }

        $products = Product::query()->findMany(array_column($items, 'product_id'))->keyBy('id');

        return array_map(function (array $item) use ($products): array {
            $product = $products->get($item['product_id'] ?? null);
            $quantity = $item['quantity'] ?? null;

            if ($product === null || ! is_numeric($quantity) || $quantity <= 0) {
                throw ValidationException::withMessages([
                    'items' => __('Cada producto necesita existir en el catálogo y tener una cantidad mayor a cero.'),
                ]);
            }

            return [$product, bcadd((string) $quantity, '0', self::QUANTITY), $item['price_type'] ?? PriceType::Public];
        }, array_values($items));
    }

    private function ensureSellable(Warehouse $warehouse): void
    {
        if (! $warehouse->is_sellable) {
            throw ValidationException::withMessages([
                'warehouse_id' => __('No se puede vender desde :warehouse.', ['warehouse' => $warehouse->name]),
            ]);
        }
    }

    private function ensureDestinationBelongsTo(?CustomerDestination $destination, ?Customer $customer): void
    {
        if ($destination !== null && $destination->customer_id !== $customer?->id) {
            throw ValidationException::withMessages([
                'customer_destination_id' => __('El destino no pertenece al cliente de la venta.'),
            ]);
        }
    }

    /**
     * Validate a discount rate (0 ≤ rate < 1) and normalize it to 4 decimals.
     */
    private function rate(string $rate): string
    {
        if (! is_numeric($rate) || $rate < 0 || $rate >= 1) {
            throw ValidationException::withMessages([
                'discount_rate' => __('El descuento debe estar entre 0 % y 99.99 %.'),
            ]);
        }

        return bcadd($rate, '0', self::RATE);
    }
}
