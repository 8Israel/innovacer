<?php

namespace App\Services;

use App\Concerns\RoundsDecimals;
use App\Models\CashMovementType;
use App\Models\Customer;
use App\Models\CustomerDestination;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationType;
use App\Models\InvoiceStatus;
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
        private CustomerBalanceService $balances,
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
        return $this->createPending($user, $warehouse, $items, $customer, $destination, $discountRate, $notes, held: true, holdNote: $holdNote);
    }

    /**
     * Ring up a sale at the counter: create it and charge it in one transaction, so a
     * failed charge (stock, credit, register) leaves nothing behind.
     *
     * @param  list<array{product_id: int, quantity: string, price_type?: PriceType}>  $items
     * @param  list<array{method: PaymentMethod, amount: string, received_amount?: string|null, reference?: string|null}>  $payments
     */
    public function sell(
        User $user,
        Warehouse $warehouse,
        array $items,
        array $payments,
        ?Customer $customer = null,
        ?CustomerDestination $destination = null,
        string $discountRate = '0',
        ?string $notes = null,
        ?SaleAuthorization $authorization = null,
    ): Sale {
        return DB::transaction(fn (): Sale => $this->complete(
            $this->createPending($user, $warehouse, $items, $customer, $destination, $discountRate, $notes, held: false),
            $user,
            $payments,
            $authorization,
        ));
    }

    /**
     * Price a cart with current prices without saving anything, for live totals.
     *
     * @param  list<array{product_id: int, quantity: string, price_type?: PriceType}>  $items
     * @return array{lines: list<array{product: Product, quantity: string, price_type: PriceType, unit_price: string, discount_rate: string, discount_amount: string, subtotal: string, iva_rate: string, iva_amount: string, ieps_rate: string, ieps_amount: string, total: string}>, subtotal: string, discount_total: string, tax_total: string, ieps_total: string, total: string}
     */
    public function quote(array $items, ?Customer $customer = null, string $discountRate = '0'): array
    {
        $discountRate = $this->rate($discountRate);
        $customerRate = $customer?->discount_rate ?? '0';

        $lines = array_map(fn (array $line): array => [
            'product' => $line[0],
            'quantity' => $line[1],
            'price_type' => $line[2],
            ...$this->priceLine($line[0], $line[1], $line[2], $customerRate, $discountRate),
        ], $this->validateItems($items));

        $sum = fn (string $key): string => array_reduce($lines, fn (string $carry, array $line) => bcadd($carry, $line[$key], self::MONEY), '0.00');

        return [
            'lines' => $lines,
            'subtotal' => $sum('subtotal'),
            'discount_total' => $sum('discount_amount'),
            'tax_total' => $sum('iva_amount'),
            'ieps_total' => $sum('ieps_amount'),
            'total' => $sum('total'),
        ];
    }

    /**
     * @param  list<array{product_id: int, quantity: string, price_type?: PriceType}>  $items
     */
    private function createPending(
        User $user,
        Warehouse $warehouse,
        array $items,
        ?Customer $customer,
        ?CustomerDestination $destination,
        string $discountRate,
        ?string $notes,
        bool $held,
        ?string $holdNote = null,
    ): Sale {
        $this->ensureSellable($warehouse);
        $this->ensureDestinationBelongsTo($destination, $customer);
        $discountRate = $this->rate($discountRate);
        $lines = $this->validateItems($items);

        return DB::transaction(function () use ($user, $warehouse, $lines, $customer, $destination, $discountRate, $notes, $held, $holdNote): Sale {
            $sale = Sale::create([
                'customer_id' => $customer?->id,
                'customer_destination_id' => $destination?->id,
                'warehouse_id' => $warehouse->id,
                'user_id' => $user->id,
                'status' => SaleStatus::Pending,
                'discount_rate' => $discountRate,
                'notes' => $notes,
                'held_at' => $held ? now() : null,
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
     * Anything left unpaid is owed on credit by the customer, if their credit allows it.
     *
     * @param  list<array{method: PaymentMethod, amount: string, received_amount?: string|null, reference?: string|null}>  $payments
     */
    public function complete(Sale $sale, User $user, array $payments, ?SaleAuthorization $authorization = null): Sale
    {
        $payments = $this->validatePayments($payments);

        return DB::transaction(function () use ($sale, $user, $payments, $authorization): Sale {
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
            $overLimitMessage = $this->ensurePaymentsFit($sale, $payments);
            $authorizer = $this->authorize($sale, $authorization, $overLimitMessage);

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
                'authorized_by_id' => $authorizer?->id,
                'authorization_reason' => $authorizer ? $authorization->reason : null,
                'cash_session_id' => $session->id,
                'inventory_operation_id' => $operation->id,
            ]);

            return $sale;
        });
    }

    /**
     * Cancel a charged sale with the owner's approval: the product goes back to the
     * lots and warehouse it left, the cash paid leaves the open register, and the sale
     * stays in the history as cancelled. Card and transfer payments are refunded
     * outside the register. Invoiced sales, and sales with customer payments or
     * returns, cannot be cancelled.
     */
    public function cancel(Sale $sale, SaleAuthorization $ownerAuthorization): Sale
    {
        $owner = $ownerAuthorization->verify(ownerOnly: true, action: __('cancelar ventas'));

        return DB::transaction(function () use ($sale, $owner, $ownerAuthorization): Sale {
            $sale = Sale::query()->with(['items.product', 'warehouse', 'customer'])->lockForUpdate()->findOrFail($sale->id);

            if ($sale->status !== SaleStatus::Completed) {
                throw ValidationException::withMessages(['sale' => __('Solo se pueden cancelar ventas cobradas.')]);
            }

            if ($sale->invoices()->where('status', InvoiceStatus::Stamped)->exists()) {
                throw ValidationException::withMessages(['sale' => __('La venta :folio está facturada: primero cancela la factura.', ['folio' => $sale->folio])]);
            }

            if ($sale->paymentAllocations()->exists() || $sale->customerReturns()->exists()) {
                throw ValidationException::withMessages([
                    'sale' => __('La venta :folio ya tiene abonos o devoluciones; corrígela con una devolución.', ['folio' => $sale->folio]),
                ]);
            }

            $cashPaid = $this->decimal($sale->payments()->where('method', PaymentMethod::Cash)->sum('amount'), self::MONEY);

            if (bccomp($cashPaid, '0', self::MONEY) === 1) {
                $this->cashSessions->recordMovement(
                    $owner,
                    CashMovementType::Outflow,
                    __('Cancelación de la venta :folio', ['folio' => $sale->folio]),
                    $cashPaid,
                    $sale->customer?->name ?? __('Público en general'),
                    $sale->folio,
                    $ownerAuthorization->reason,
                );
            }

            $operation = InventoryOperation::create([
                'type' => InventoryOperationType::Reversal,
                'user_id' => $owner->id,
                'reversal_of_id' => $sale->inventory_operation_id,
                'reference' => $sale->folio,
                'note' => $ownerAuthorization->reason,
                'occurred_at' => now(),
            ]);

            foreach ($sale->items as $item) {
                $this->inventory->restockReturn($operation, $item, $item->quantity, $sale->warehouse);
            }

            $sale->update([
                'status' => SaleStatus::Canceled,
                'canceled_at' => now(),
                'canceled_by_id' => $owner->id,
                'cancel_reason' => $ownerAuthorization->reason,
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

        if ($product->tax_rate === null) {
            throw ValidationException::withMessages([
                'items' => __('El producto :product no tiene tasa de IVA; captúrala en el producto antes de venderlo.', ['product' => $product->name]),
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
     * Payments may not exceed the total. What they leave unpaid goes on credit, which
     * needs a customer with credit enabled. The customer row is locked so two credit
     * sales cannot both slip under the limit.
     *
     * @param  list<array{method: PaymentMethod, amount: string, received_amount?: string|null, reference?: string|null}>  $payments
     * @return string|null why the sale needs the owner's approval for going over the
     *                     credit limit, or null when it stays within it.
     */
    private function ensurePaymentsFit(Sale $sale, array $payments): ?string
    {
        $paid = array_reduce($payments, fn (string $carry, array $payment) => bcadd($carry, $payment['amount'], self::MONEY), '0');

        if (bccomp($paid, $sale->total, self::MONEY) === 1) {
            throw ValidationException::withMessages([
                'payments' => __('Los pagos ($:paid) rebasan el total de la venta ($:total).', ['paid' => $paid, 'total' => $sale->total]),
            ]);
        }

        $onCredit = bcsub($sale->total, $paid, self::MONEY);

        if (bccomp($onCredit, '0', self::MONEY) === 0) {
            return null;
        }

        $customer = $sale->customer_id === null ? null : Customer::query()->lockForUpdate()->find($sale->customer_id);

        if ($customer === null) {
            throw ValidationException::withMessages([
                'payments' => __('Una venta sin cliente debe pagarse completa: faltan $:missing.', ['missing' => $onCredit]),
            ]);
        }

        if (! $customer->hasCredit()) {
            throw ValidationException::withMessages([
                'payments' => __(':customer no tiene crédito: la venta debe pagarse completa, faltan $:missing.', [
                    'customer' => $customer->name,
                    'missing' => $onCredit,
                ]),
            ]);
        }

        if ($customer->credit_limit === null) {
            return null;
        }

        $balanceAfterSale = bcadd($this->balances->balance($customer), $onCredit, self::MONEY);

        if (bccomp($balanceAfterSale, $customer->credit_limit, self::MONEY) <= 0) {
            return null;
        }

        return __('Con esta venta :customer debería $:balance y su límite es $:limit. Se necesita autorización del dueño.', [
            'customer' => $customer->name,
            'balance' => $balanceAfterSale,
            'limit' => $customer->credit_limit,
        ]);
    }

    /**
     * An extra discount on the sale needs the approval of the owner or the clerk;
     * going over the credit limit needs the owner's. Both type their password.
     *
     * @return User|null who approved, or null when nothing needed approval.
     */
    private function authorize(Sale $sale, ?SaleAuthorization $authorization, ?string $overLimitMessage): ?User
    {
        $hasExtraDiscount = bccomp($sale->discount_rate, '0', self::RATE) === 1;

        if (! $hasExtraDiscount && $overLimitMessage === null) {
            return null;
        }

        if ($authorization === null) {
            throw ValidationException::withMessages($overLimitMessage !== null
                ? ['payments' => $overLimitMessage]
                : ['authorization' => __('El descuento extra de la venta necesita autorización.')]);
        }

        return $authorization->verify(
            ownerOnly: $overLimitMessage !== null,
            action: __('que se rebase el límite de crédito'),
        );
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
