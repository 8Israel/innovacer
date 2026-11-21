<?php

namespace App\Services;

use App\Concerns\RoundsDecimals;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceStatus;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Invoices built from one or more charged sales of a customer (RF-34,
 * docs/diseno/Facturar.html): the concepts are what was actually charged (customer
 * and sale discounts applied, returns taken out), with each line's IVA and IEPS.
 * A sale is invoiced once; if any of them is still owed, the invoice is paid in
 * installments (PPD, payment form 99) and later gets payment complements.
 */
class InvoiceFromSalesService
{
    use RoundsDecimals;

    private const MONEY = 2;

    private const QUANTITY = 3;

    public function __construct(private CustomerBalanceService $balances) {}

    /**
     * Charged sales of the customer not covered by a draft or stamped invoice, newest first.
     *
     * @return Collection<int, array{sale: Sale, pending: string, payment: string}>
     */
    public function uninvoicedSales(Customer $customer): Collection
    {
        return $customer->sales()
            ->with('payments')
            ->where('status', SaleStatus::Completed)
            ->whereDoesntHave('invoices', fn ($invoices) => $invoices->whereIn('status', [InvoiceStatus::Draft, InvoiceStatus::Stamped]))
            ->latest('sold_at')
            ->get()
            ->map(function (Sale $sale): array {
                $pending = $this->balances->pending($sale);
                $methods = $sale->payments->pluck('method')->unique();

                return [
                    'sale' => $sale,
                    'pending' => $pending,
                    'payment' => match (true) {
                        bccomp($pending, '0', self::MONEY) === 1 => __('A crédito sin pagar'),
                        $methods->count() === 1 => $methods->first()->label(),
                        $methods->count() > 1 => __('Mixto'),
                        default => __('Pagada con abonos'),
                    },
                ];
            });
    }

    /**
     * The invoice lines of the sales: one per product, price, discount and rates,
     * with the quantities kept by the customer (returns taken out). Each line keeps
     * the list price and its discount, as the CFDI asks; IEPS is charged on the net
     * amount and IVA on the net amount plus IEPS, as in the sale.
     *
     * @param  Collection<int, Sale>  $sales
     * @return Collection<int, array{product: Product, quantity: string, unit_price: string, discount_rate: string, tax_rate: string, ieps_rate: string, subtotal: string, discount: string, ieps_amount: string, tax_amount: string, total: string}>
     */
    public function concepts(Collection $sales): Collection
    {
        $items = SaleItem::query()
            ->with('product')
            ->whereIn('sale_id', $sales->pluck('id'))
            ->withSum('customerReturnItems as returned_quantity', 'quantity')
            ->get();

        return $items
            ->map(function (SaleItem $item): ?array {
                $kept = bcsub($item->quantity, $this->decimal($item->returned_quantity, self::QUANTITY), self::QUANTITY);

                if (bccomp($kept, '0', self::QUANTITY) <= 0) {
                    return null;
                }

                return [
                    'product' => $item->product,
                    'quantity' => $kept,
                    'unit_price' => $item->unit_price,
                    'discount_rate' => bccomp($item->subtotal, '0', self::MONEY) === 1 ? bcdiv($item->discount_amount, $item->subtotal, 6) : '0',
                    'tax_rate' => $item->iva_rate,
                    'ieps_rate' => $item->ieps_rate,
                ];
            })
            ->filter()
            ->groupBy(fn (array $line) => implode('|', [$line['product']->id, $line['unit_price'], $line['discount_rate'], $line['tax_rate'], $line['ieps_rate']]))
            ->map(function (Collection $lines): array {
                $first = $lines->first();
                $quantity = $lines->reduce(fn (string $carry, array $line) => bcadd($carry, $line['quantity'], self::QUANTITY), '0');
                $subtotal = $this->roundHalfUp(bcmul($quantity, $first['unit_price'], 8), self::MONEY);
                $discount = $this->roundHalfUp(bcmul($subtotal, $first['discount_rate'], 8), self::MONEY);
                $net = bcsub($subtotal, $discount, self::MONEY);
                $ieps = $this->roundHalfUp(bcmul($net, $first['ieps_rate'], 8), self::MONEY);
                $iva = $this->roundHalfUp(bcmul(bcadd($net, $ieps, self::MONEY), $first['tax_rate'], 8), self::MONEY);

                return [
                    ...$first,
                    'quantity' => $quantity,
                    'subtotal' => $subtotal,
                    'discount' => $discount,
                    'ieps_amount' => $ieps,
                    'tax_amount' => $iva,
                    'total' => bcadd(bcadd($net, $ieps, self::MONEY), $iva, self::MONEY),
                ];
            })
            ->sortBy(fn (array $line) => $line['product']->name)
            ->values();
    }

    /**
     * How the invoice is paid: in one go with the method that paid the most, or in
     * installments (PPD, "99 por definir") while any of the sales is owed.
     *
     * @param  Collection<int, Sale>  $sales
     * @return array{payment_method: string, payment_form: string}
     */
    public function paymentTerms(Collection $sales): array
    {
        $owed = $sales->contains(fn (Sale $sale) => bccomp($this->balances->pending($sale), '0', self::MONEY) === 1);

        if ($owed) {
            return ['payment_method' => 'PPD', 'payment_form' => '99'];
        }

        $byMethod = [];

        foreach ($sales as $sale) {
            foreach ($sale->payments as $payment) {
                $byMethod[$payment->method->value] = bcadd($byMethod[$payment->method->value] ?? '0', $payment->amount, self::MONEY);
            }

            foreach ($sale->paymentAllocations()->with('customerPayment')->get() as $allocation) {
                $method = $allocation->customerPayment->method->value;
                $byMethod[$method] = bcadd($byMethod[$method] ?? '0', $allocation->amount, self::MONEY);
            }
        }

        arsort($byMethod, SORT_NUMERIC);
        $method = PaymentMethod::tryFrom((string) array_key_first($byMethod));

        return ['payment_method' => 'PUE', 'payment_form' => match ($method) {
            PaymentMethod::Cash => '01',
            PaymentMethod::Transfer => '03',
            PaymentMethod::Card => '04',
            null => '99',
        }];
    }

    /**
     * What stops the invoice from being stamped: missing fiscal data of the
     * customer and products without SAT keys.
     *
     * @param  Collection<int, array{product: Product}>  $concepts
     * @return list<string>
     */
    public function blockers(Customer $customer, Collection $concepts): array
    {
        $blockers = [];
        $missing = collect(['rfc' => __('RFC'), 'tax_system' => __('régimen fiscal'), 'zip_code' => __('código postal fiscal')])
            ->filter(fn (string $label, string $field) => blank($customer->{$field}));

        if ($missing->isNotEmpty()) {
            $blockers[] = __('Al cliente le falta: :fields.', ['fields' => $missing->join(', ', ' y ')]);
        }

        $withoutKeys = $concepts->pluck('product')->filter(fn (Product $product) => blank($product->product_key) || blank($product->unit_key))->pluck('name')->unique();

        if ($withoutKeys->isNotEmpty()) {
            $blockers[] = trans_choice('Falta la clave SAT de :products. Complétala para poder emitir.|Falta la clave SAT de :products. Complétalas para poder emitir.', $withoutKeys->count(), ['products' => $withoutKeys->join(', ', ' y ')]);
        }

        return $blockers;
    }

    /**
     * Create the draft invoice of the sales, ready to be stamped.
     *
     * @param  list<int>  $saleIds
     */
    public function create(Customer $customer, array $saleIds, string $cfdiUse): Invoice
    {
        if ($saleIds === []) {
            throw ValidationException::withMessages(['sale_ids' => __('Elige al menos una venta para facturar.')]);
        }

        return DB::transaction(function () use ($customer, $saleIds, $cfdiUse): Invoice {
            $sales = Sale::query()->with('payments')->whereKey($saleIds)->lockForUpdate()->get();

            if ($sales->count() !== count(array_unique($saleIds)) || $sales->contains(fn (Sale $sale) => $sale->customer_id !== $customer->id || $sale->status !== SaleStatus::Completed)) {
                throw ValidationException::withMessages(['sale_ids' => __('Solo se facturan ventas cobradas de este cliente.')]);
            }

            if ($sales->contains(fn (Sale $sale) => $sale->invoices()->whereIn('status', [InvoiceStatus::Draft, InvoiceStatus::Stamped])->exists())) {
                throw ValidationException::withMessages(['sale_ids' => __('Una de las ventas ya está facturada.')]);
            }

            $concepts = $this->concepts($sales);

            if ($concepts->isEmpty()) {
                throw ValidationException::withMessages(['sale_ids' => __('Esas ventas ya no tienen productos por facturar (se devolvieron).')]);
            }

            $blockers = $this->blockers($customer, $concepts);

            if ($blockers !== []) {
                throw ValidationException::withMessages(['invoice' => implode(' ', $blockers)]);
            }

            $invoice = new Invoice([
                'customer_id' => $customer->id,
                ...$this->paymentTerms($sales),
                'cfdi_use' => $cfdiUse,
                'currency' => 'MXN',
            ]);
            $invoice->forceFill(['type' => InvoiceType::Income, 'status' => InvoiceStatus::Draft])->save();

            foreach ($concepts as $concept) {
                $invoice->items()->create([
                    'product_id' => $concept['product']->id,
                    'quantity' => $concept['quantity'],
                    'unit_price' => $concept['unit_price'],
                    'tax_rate' => $concept['tax_rate'],
                    'subtotal' => $concept['subtotal'],
                    'discount' => $concept['discount'],
                    'tax_amount' => $concept['tax_amount'],
                    'ieps_rate' => $concept['ieps_rate'],
                    'ieps_amount' => $concept['ieps_amount'],
                    'total' => $concept['total'],
                ]);
            }

            $sum = fn (string $key) => $concepts->reduce(fn (string $carry, array $line) => bcadd($carry, $line[$key], self::MONEY), '0');
            $invoice->forceFill([
                'subtotal' => bcsub($sum('subtotal'), $sum('discount'), self::MONEY),
                'tax_total' => bcadd($sum('tax_amount'), $sum('ieps_amount'), self::MONEY),
                'total' => $sum('total'),
            ])->save();
            $invoice->sales()->attach($sales->pluck('id'));

            return $invoice;
        });
    }
}
