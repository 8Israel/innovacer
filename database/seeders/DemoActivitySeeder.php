<?php

namespace Database\Seeders;

use App\Models\CashMovementCategory;
use App\Models\CashMovementType;
use App\Models\CashSession;
use App\Models\CashSessionStatus;
use App\Models\Customer;
use App\Models\CustomerDestination;
use App\Models\LoanEntry;
use App\Models\PaymentMethod;
use App\Models\PriceType;
use App\Models\Product;
use App\Models\RefundMethod;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashSessionService;
use App\Services\CustomerPaymentService;
use App\Services\CustomerReturnService;
use App\Services\InventoryService;
use App\Services\PhysicalCountService;
use App\Services\SaleService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Ten days of activity on top of DemoSeeder, recorded through the same services the
 * screens use, so every report, closing and balance can be tried with real-looking
 * data: daily registers (one short, one over), cash, card, transfer, mixed and credit
 * sales by account, wholesale prices, customer payments, returns (cash, to the
 * account and to waste), expenses and freight, consignment and bought entries,
 * transfers, a loan from Francisco partly repaid, a sample, a physical count, and
 * today's register still open with a held sale.
 *
 *     php artisan db:seed --class=DemoSeeder && php artisan db:seed --class=DemoActivitySeeder
 *
 * Owner-only actions (cancelling sales, paying suppliers, authorizing discounts) are
 * left out: they ask for an owner's password.
 */
class DemoActivitySeeder extends Seeder
{
    private User $user;

    private Warehouse $mostrador;

    /** @var array<string, Product> */
    private array $products;

    public function __construct(
        private CashSessionService $cash,
        private SaleService $sales,
        private CustomerPaymentService $payments,
        private CustomerReturnService $returns,
        private InventoryService $inventory,
        private PhysicalCountService $counts,
    ) {}

    public function run(): void
    {
        if (Sale::query()->exists()) {
            $this->command?->warn('Ya hay ventas registradas; no se agregó actividad de demo.');

            return;
        }

        $this->user = User::query()->orderBy('id')->firstOrFail();
        $this->mostrador = Warehouse::query()->where('code', 'MOS')->firstOrFail();
        $this->products = Product::query()->get()->keyBy('sku')->all();

        $bodega = Warehouse::query()->where('code', 'ORT')->firstOrFail();
        $consigna = Warehouse::query()->where('code', 'CFR')->firstOrFail();
        $supplier = fn (string $name) => Supplier::query()->where('name', $name)->firstOrFail();
        $customer = fn (string $name) => Customer::query()->where('name', $name)->firstOrFail();
        $account = fn (string $name) => CustomerDestination::query()->where('name', $name)->firstOrFail();

        $francisco = $customer('Francisco Castañón');
        $ernesto = $customer('Ernesto Castaño');
        $melendez = $customer('Ing. Meléndez');
        $carrasco = $customer('Alejandro Carrasco');
        $reynosa = $account('Reynosa');
        $jalincher = $account('Jalincher');
        // Taken before the clock is moved day by day below.
        $today = CarbonImmutable::today();
        $start = $today->subDays(9);

        // Día 1: llega mercancía, se surte el mostrador y se vende.
        $this->day($start, '1000', function (CarbonImmutable $day) use ($supplier, $bodega, $francisco, $ernesto, $reynosa) {
            $this->at($day->setTime(8, 30));
            $this->inventory->receive($this->user, $supplier('Biotexa'), $this->mostrador, [
                $this->line('UNI-20', 20, 980), $this->line('UCA-20', 15, 1050), $this->line('UMG-20', 12, 1150),
            ], consignment: true, reference: 'Remisión B-2210');
            $this->inventory->receive($this->user, $supplier('Coda'), $this->mostrador, [$this->line('CAL-01', 40, 120)], consignment: true, reference: 'Remisión C-981');
            $this->at($day->setTime(9, 0));
            $this->inventory->transfer($this->user, $bodega, $this->mostrador, [$this->line('COR-20', 8), $this->line('BGF-01', 12)], 'Surtir el mostrador');

            $this->at($day->setTime(10, 15));
            $this->cashSale([$this->line('CAL-01', 2), $this->line('UNI-20', 1)], received: '2000');
            $this->at($day->setTime(11, 40));
            $this->sales->sell($this->user, $this->mostrador, [$this->line('UNI-20', 3), $this->line('UMG-20', 2)], [], $francisco, $reynosa, notes: 'Para el rancho de Reynosa');
            $this->at($day->setTime(13, 5));
            $this->paidSale([$this->line('COR-20', 1)], PaymentMethod::Card, $ernesto, reference: 'Visa 0921');
            $this->at($day->setTime(14, 30));
            $this->cash->recordMovement($this->user, CashMovementType::Outflow, 'Comida', '150', 'Encargada', category: CashMovementCategory::Expense);
        }, counted: null);

        // Día 2: mayoreo, crédito sin cuentas y un abono; el corte sale $100 corto.
        $this->day($start->addDay(), null, function (CarbonImmutable $day) use ($melendez, $carrasco, $francisco, $reynosa) {
            $this->at($day->setTime(9, 20));
            $this->sales->sell($this->user, $this->mostrador, [$this->line('UCA-20', 4)], [], $melendez, notes: 'Entrega en su bodega');
            $this->at($day->setTime(10, 45));
            $this->paidSale([$this->line('CAL-01', 12, wholesale: true)], PaymentMethod::Cash, $carrasco, received: '2100');
            $this->at($day->setTime(12, 10));
            $this->payments->register($this->user, $francisco, $reynosa, PaymentMethod::Cash, '2000');
            $this->at($day->setTime(16, 0));
            $this->cashSale([$this->line('UNI-20', 1)]);
        }, counted: '-100', note: 'Cambio mal entregado');

        // Día 3: préstamo de Francisco, pago mixto, crédito con IEPS y una devolución; sobran $20.
        $this->day($start->addDays(2), null, function (CarbonImmutable $day) use ($consigna, $ernesto) {
            $this->at($day->setTime(8, 50));
            $this->inventory->transfer($this->user, $consigna, $this->mostrador, [$this->line('UCA-20', 5)], 'Se acabó en el mostrador');
            $this->at($day->setTime(10, 0));
            $this->sales->sell($this->user, $this->mostrador, [$this->line('UCA-20', 2), $this->line('BGF-01', 1)], [], $ernesto);
            $this->at($day->setTime(11, 30));
            $total = $this->sales->quote([$this->line('UMG-20', 1), $this->line('CAL-01', 3)])['total'];
            $this->sales->sell($this->user, $this->mostrador, [$this->line('UMG-20', 1), $this->line('CAL-01', 3)], [
                ['method' => PaymentMethod::Cash, 'amount' => '1000', 'received_amount' => '1000'],
                ['method' => PaymentMethod::Card, 'amount' => bcsub($total, '1000', 2), 'reference' => 'Débito 4410'],
            ]);
            $this->at($day->setTime(13, 15));
            $firstSale = Sale::query()->whereNull('customer_id')->orderBy('id')->firstOrFail();
            $this->returns->register($this->user, $firstSale, [['sale_item_id' => $firstSale->items()->whereHas('product', fn ($q) => $q->where('sku', 'CAL-01'))->value('id'), 'quantity' => '1']], RefundMethod::Cash, true, 'El cliente compró de más');
        }, counted: '20', note: 'Cliente no esperó el cambio');

        // Día 4: compras de contado a NH y TN, una muestra, crédito a Jalincher y un abono por transferencia.
        $this->day($start->addDays(3), null, function (CarbonImmutable $day) use ($supplier, $francisco, $jalincher, $ernesto) {
            $this->at($day->setTime(8, 40));
            $this->inventory->receive($this->user, $supplier('NH'), $this->mostrador, [$this->line('MRB-01', 20, 210)], consignment: false, reference: 'Factura NH-5521');
            $this->inventory->receive($this->user, $supplier('TN'), $this->mostrador, [$this->line('MAN-16', 100, 15)], consignment: false, reference: 'Factura TN-330');
            $this->at($day->setTime(9, 30));
            $this->inventory->exit($this->user, $this->mostrador, [$this->line('MRB-01', 1)], 'Muestra para cliente', 'Ing. agrónomo');
            $this->at($day->setTime(11, 0));
            $this->sales->sell($this->user, $this->mostrador, [$this->line('UMG-20', 2), $this->line('BGF-01', 1)], [], $francisco, $jalincher);
            $this->at($day->setTime(12, 20));
            $this->paidSale([$this->line('MRB-01', 3)], PaymentMethod::Transfer, reference: 'SPEI 88214');
            $this->at($day->setTime(15, 10));
            $this->payments->register($this->user, $ernesto, null, PaymentMethod::Transfer, '3000', 'SPEI 77102');
        });

        // Día 5: reposición parcial del préstamo, venta de contado, devolución a la cuenta y un flete.
        $this->day($start->addDays(4), null, function (CarbonImmutable $day) use ($supplier, $melendez, $francisco) {
            $this->at($day->setTime(9, 0));
            $this->inventory->repayLoan($this->user, LoanEntry::query()->firstOrFail(), '3', $this->mostrador, 'Primera reposición');
            $this->at($day->setTime(10, 30));
            $this->cashSale([$this->line('CAL-01', 3)], received: '600');
            $this->at($day->setTime(11, 45));
            $this->payments->register($this->user, $melendez, null, PaymentMethod::Cash, '1000');
            $this->at($day->setTime(13, 0));
            $jalincherSale = Sale::query()->where('customer_id', $francisco->id)->whereNotNull('customer_destination_id')->latest('id')->firstOrFail();
            $this->returns->register($this->user, $jalincherSale, [['sale_item_id' => $jalincherSale->items()->whereHas('product', fn ($q) => $q->where('sku', 'BGF-01'))->value('id'), 'quantity' => '1']], RefundMethod::AccountCredit, true, 'Producto dañado');
            $this->at($day->setTime(16, 20));
            $this->cash->recordMovement($this->user, CashMovementType::Outflow, 'Flete', '780', null, 'Recibo 311', category: CashMovementCategory::SupplierExpense, supplier: $supplier('Coda'));
        });

        // Día 6: conteo físico del mostrador (faltó un Calcio) y ventas.
        $this->day($start->addDays(5), null, function (CarbonImmutable $day) use ($carrasco) {
            $this->at($day->setTime(8, 15));
            $count = $this->counts->start($this->user, $this->mostrador, 'Conteo de fin de semana');
            foreach ($count->items()->with('product')->get() as $item) {
                $counted = $item->product->sku === 'CAL-01' ? bcsub($item->system_quantity, '1', 3) : $item->system_quantity;
                $this->counts->record($count, $item->product, $counted);
            }
            $this->counts->apply($this->user, $count);
            $this->at($day->setTime(10, 50));
            $this->paidSale([$this->line('UNI-20', 2)], PaymentMethod::Cash, $carrasco, received: '2500');
            $this->at($day->setTime(12, 40));
            $this->cashSale([$this->line('CAL-01', 4), $this->line('MRB-01', 1)]);
        });

        // Día 7: devolución a merma de un producto dañado y ventas.
        $this->day($start->addDays(6), null, function (CarbonImmutable $day) use ($ernesto) {
            $this->at($day->setTime(9, 40));
            $ernestoSale = Sale::query()->where('customer_id', $ernesto->id)->whereDoesntHave('payments')->latest('id')->firstOrFail();
            $this->returns->register($this->user, $ernestoSale, [['sale_item_id' => $ernestoSale->items()->whereHas('product', fn ($q) => $q->where('sku', 'UCA-20'))->value('id'), 'quantity' => '1']], RefundMethod::Cash, false, 'Garrafa rota');
            $this->at($day->setTime(11, 10));
            $this->paidSale([$this->line('COR-20', 2)], PaymentMethod::Transfer, reference: 'SPEI 90133');
            $this->at($day->setTime(13, 35));
            $this->cashSale([$this->line('BGF-01', 2)]);
        });

        // Día 8: venta grande a crédito con mayoreo y abono a Reynosa.
        $this->day($start->addDays(7), null, function (CarbonImmutable $day) use ($francisco, $reynosa) {
            $this->at($day->setTime(10, 0));
            $this->sales->sell($this->user, $this->mostrador, [$this->line('UNI-20', 10, wholesale: true)], [], $francisco, $reynosa, notes: 'Pedido de temporada');
            $this->at($day->setTime(12, 30));
            $this->payments->register($this->user, $francisco, $reynosa, PaymentMethod::Transfer, '5000', 'SPEI 11873');
            $this->at($day->setTime(15, 45));
            $this->cashSale([$this->line('CAL-01', 6)]);
        });

        // Día 9: ventas normales.
        $this->day($start->addDays(8), null, function (CarbonImmutable $day) use ($melendez) {
            $this->at($day->setTime(9, 50));
            $this->cashSale([$this->line('UMG-20', 1), $this->line('MRB-01', 2)], received: '2500');
            $this->at($day->setTime(14, 15));
            $this->sales->sell($this->user, $this->mostrador, [$this->line('MRB-01', 4), $this->line('CAL-01', 2)], [], $melendez);
        });

        // Hoy: caja abierta, dos ventas y una pendiente.
        $this->at($today->setTime(8, 30));
        $this->cash->open($this->user, CashSession::query()->where('status', CashSessionStatus::Closed)->latest('closed_at')->value('cash_left') ?? '1000');
        $this->at($today->setTime(9, 45));
        $this->cashSale([$this->line('UNI-20', 1), $this->line('CAL-01', 2)], received: '2000');
        $this->at($today->setTime(10, 20));
        $this->paidSale([$this->line('BGF-01', 1)], PaymentMethod::Card, reference: 'Visa 1180');
        $this->at($today->setTime(11, 0));
        $this->sales->hold($this->user, $this->mostrador, [$this->line('UCA-20', 2)], $carrasco, holdNote: 'Viene por ella a las 4');

        Carbon::setTestNow();
    }

    /**
     * Open the register in the morning, run the day and close it in the evening,
     * counting what was expected plus a difference when there is one.
     */
    private function day(CarbonImmutable $day, ?string $openingCash, callable $activity, ?string $counted = '0', ?string $note = null): void
    {
        $this->at($day->setTime(8, 0));
        $previousCashLeft = CashSession::query()->where('status', CashSessionStatus::Closed)->latest('closed_at')->value('cash_left');
        $this->cash->open($this->user, $openingCash ?? $previousCashLeft ?? '1000');

        $activity($day);

        $this->at($day->setTime(19, 0));
        $countedCash = bcadd($this->cash->expectedCash($this->cash->current()), $counted ?? '0', 2);
        $cashLeft = bccomp($countedCash, '1000', 2) < 0 ? $countedCash : '1000';
        $this->cash->close($this->user, $countedCash, $cashLeft, $note);
    }

    private function at(CarbonImmutable $moment): void
    {
        Carbon::setTestNow($moment);
    }

    /**
     * @return array{product_id: int, quantity: string, unit_cost?: string, price_type?: PriceType}
     */
    private function line(string $sku, int $quantity, ?int $cost = null, bool $wholesale = false): array
    {
        return array_filter([
            'product_id' => $this->products[$sku]->id,
            'quantity' => (string) $quantity,
            'unit_cost' => $cost === null ? null : (string) $cost,
            'price_type' => $wholesale ? PriceType::Wholesale : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * A walk-in sale paid in cash, with change when more is received.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function cashSale(array $items, ?string $received = null): Sale
    {
        return $this->paidSale($items, PaymentMethod::Cash, received: $received);
    }

    /**
     * A sale paid in full with one method.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function paidSale(array $items, PaymentMethod $method, ?Customer $customer = null, ?string $reference = null, ?string $received = null): Sale
    {
        $total = $this->sales->quote($items, $customer)['total'];

        return $this->sales->sell($this->user, $this->mostrador, $items, [[
            'method' => $method,
            'amount' => $total,
            'received_amount' => $method === PaymentMethod::Cash ? ($received !== null && bccomp($received, $total, 2) >= 0 ? $received : $total) : null,
            'reference' => $reference,
        ]], $customer);
    }
}
