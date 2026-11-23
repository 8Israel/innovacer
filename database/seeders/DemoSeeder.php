<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\StockMinimum;
use App\Models\Supplier;
use App\Models\SupplierSettlementType;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Example data from the screen designs (docs/diseno/), so the client can try the
 * system with names they recognize. Run it on an empty database:
 *
 *     php artisan migrate:fresh && php artisan db:seed --class=DemoSeeder
 *
 * When the database already has users they are kept and used; otherwise users
 * "dueno@innovacer.test" and "encargada@innovacer.test" are created with the
 * temporary password below and must change it on first login.
 * Taxes and SAT keys are left pending until the accountant confirms them.
 */
class DemoSeeder extends Seeder
{
    public const TEMPORARY_PASSWORD = 'Innovacer2026';

    public function run(InventoryService $inventory): void
    {
        if (Warehouse::query()->where('code', 'MOS')->exists()) {
            $this->command?->warn('Los datos de demo ya están cargados; no se hizo nada.');

            return;
        }

        DB::transaction(function () use ($inventory): void {
            $owner = $this->users();
            [$mostrador, $bodegaOrtitec, $consignaFrancisco, $suppliers] = $this->warehousesAndSuppliers();
            $products = $this->products();
            $this->stock($inventory, $owner, $mostrador, $bodegaOrtitec, $consignaFrancisco, $suppliers, $products);
            $this->customers();
        });
    }

    /**
     * The user the demo stock is recorded under: an existing owner, any existing user,
     * or the demo owner created here when the database has no users yet.
     */
    private function users(): User
    {
        $existing = User::query()->orderByRaw('role = ? desc', [UserRole::Owner->value])->orderBy('id')->first();

        if ($existing !== null) {
            return $existing;
        }

        $owner = User::query()->forceCreate([
            'name' => 'Dueño',
            'email' => 'dueno@innovacer.test',
            'password' => self::TEMPORARY_PASSWORD,
            'role' => UserRole::Owner,
            'must_change_password' => true,
        ]);

        User::query()->forceCreate([
            'name' => 'Encargada',
            'email' => 'encargada@innovacer.test',
            'password' => self::TEMPORARY_PASSWORD,
            'role' => UserRole::Clerk,
            'must_change_password' => true,
        ]);

        return $owner;
    }

    /**
     * @return array{0: Warehouse, 1: Warehouse, 2: Warehouse, 3: array<string, Supplier>}
     */
    private function warehousesAndSuppliers(): array
    {
        $suppliers = collect([
            'Ortitec' => SupplierSettlementType::Consignment,
            'Biotexa' => SupplierSettlementType::Consignment,
            'Coda' => SupplierSettlementType::Consignment,
            'NH' => SupplierSettlementType::Purchase,
            'TN' => SupplierSettlementType::Purchase,
            'Tierra Verde' => SupplierSettlementType::Consignment,
            'Francisco Rodríguez' => SupplierSettlementType::InKind,
        ])->map(fn (SupplierSettlementType $type, string $name) => Supplier::create([
            'name' => $name,
            'settlement_type' => $type,
        ]))->all();

        $mostrador = Warehouse::create(['name' => 'Mostrador Innovacer', 'code' => 'MOS', 'is_sellable' => true]);
        $bodegaOrtitec = Warehouse::create(['name' => 'Bodega Ortitec', 'code' => 'ORT']);
        $consignaFrancisco = Warehouse::create([
            'name' => 'Consigna Francisco',
            'code' => 'CFR',
            'lender_id' => $suppliers['Francisco Rodríguez']->id,
        ]);

        return [$mostrador, $bodegaOrtitec, $consignaFrancisco, $suppliers];
    }

    /**
     * @return array<string, Product> keyed by internal code.
     */
    private function products(): array
    {
        $fertilizantes = Category::create(['name' => 'Fertilizantes']);
        $coadyuvantes = Category::create(['name' => 'Coadyuvantes']);
        $materiales = Category::create(['name' => 'Materiales']);

        $catalog = [
            // code => [name, brand, presentation, unit, category, reference cost, public price, wholesale price, IVA, IEPS, SAT product key, SAT unit key]
            // Fertilizers go at IVA 0 %; the rest at 16 % (to confirm with the accountant). Bigfix carries IEPS to try it out.
            'UNI-20' => ['Ultra Nitrógeno 20 L', 'Biotexa', '20 L', 'garrafa', $fertilizantes, 980, 1250, 1180, 0, null, '10171500', 'H87'],
            'UCA-20' => ['Ultra Calcio 20 L', 'Biotexa', '20 L', 'garrafa', $fertilizantes, 1050, 1350, 1280, 0, null, '10171500', 'H87'],
            'UMG-20' => ['Ultra Magnesio 20 L', 'Biotexa', '20 L', 'garrafa', $fertilizantes, 1150, 1480, 1400, 0, null, '10171500', 'H87'],
            'COR-20' => ['Core 20 L', 'Ortitec', '20 L', 'garrafa', $fertilizantes, 1600, 2100, 2000, 0, null, '10171500', 'H87'],
            'BGF-01' => ['Bigfix 1 L', 'Ortitec', '1 L', 'botella', $coadyuvantes, 380, 520, 490, 0.16, 0.06, '10191509', 'H87'],
            'CAL-01' => ['Calcio 1 L', 'Coda', '1 L', 'botella', $fertilizantes, 120, 185, 170, 0, null, '10171500', 'H87'],
            'MRB-01' => ['Mirabis 1 L', 'NH', '1 L', 'botella', $coadyuvantes, 210, 290, 270, 0.16, null, null, null],
            'MAN-16' => ['Manguera de riego 16 mm', 'TN', '16 mm', 'metro', $materiales, 15, null, null, 0.16, null, '40142000', 'MTR'],
        ];

        return collect($catalog)->map(fn (array $data, string $code) => Product::create([
            'sku' => $code,
            'name' => $data[0],
            'brand' => $data[1],
            'presentation' => $data[2],
            'unit' => $data[3],
            'category_id' => $data[4]->id,
            'reference_cost' => $data[5],
            'unit_price' => $data[6],
            'wholesale_price' => $data[7],
            'wholesale_min_quantity' => $data[7] === null ? null : 10,
            'tax_rate' => $data[8],
            'ieps_rate' => $data[9],
            'product_key' => $data[10],
            'unit_key' => $data[11],
            'unit_name' => $data[11] === null ? null : ($data[11] === 'MTR' ? 'Metro' : 'Pieza'),
        ]))->all();
    }

    /**
     * @param  array<string, Supplier>  $suppliers
     * @param  array<string, Product>  $products
     */
    private function stock(
        InventoryService $inventory,
        User $owner,
        Warehouse $mostrador,
        Warehouse $bodegaOrtitec,
        Warehouse $consignaFrancisco,
        array $suppliers,
        array $products,
    ): void {
        $line = fn (string $code, int $quantity, ?int $cost = null) => [
            'product_id' => $products[$code]->id,
            'quantity' => (string) $quantity,
            'unit_cost' => $cost === null ? null : (string) $cost,
        ];

        $inventory->receive($owner, $suppliers['Biotexa'], $mostrador, [
            $line('UNI-20', 12, 980),
            $line('UCA-20', 3, 1050),
            $line('UMG-20', 6, 1150),
        ], consignment: true, reference: 'Carga inicial', note: 'Existencia inicial de demo');

        $inventory->receive($owner, $suppliers['Coda'], $mostrador, [$line('CAL-01', 10, 120)], consignment: true, reference: 'Carga inicial');
        $inventory->receive($owner, $suppliers['NH'], $mostrador, [$line('MRB-01', 8, 210)], consignment: false, reference: 'Carga inicial');
        $inventory->receive($owner, $suppliers['TN'], $mostrador, [$line('MAN-16', 50, 15)], consignment: false, reference: 'Carga inicial');

        $inventory->receive($owner, $suppliers['Ortitec'], $bodegaOrtitec, [
            $line('COR-20', 15, 1600),
            $line('BGF-01', 24, 380),
        ], consignment: true, reference: 'Carga inicial');

        $inventory->receive($owner, $suppliers['Francisco Rodríguez'], $consignaFrancisco, [
            $line('UNI-20', 25),
            $line('UCA-20', 44),
            $line('UMG-20', 10),
        ], consignment: true, reference: 'Carga inicial');

        StockMinimum::create(['product_id' => $products['UNI-20']->id, 'warehouse_id' => $mostrador->id, 'min_quantity' => 5]);
    }

    private function customers(): void
    {
        $castanon = Customer::create([
            'name' => 'Francisco Castañón',
            'phone' => '899 000 0001',
            'credit_enabled' => true,
            'discount_rate' => 0.10,
        ]);
        $castanon->destinations()->createMany([['name' => 'Reynosa'], ['name' => 'Jalincher']]);

        Customer::create([
            'name' => 'Ernesto Castaño',
            'credit_enabled' => true,
            'credit_limit' => 50000,
            'rfc' => 'CAGE850312QK4',
            'tax_system' => '612',
            'zip_code' => '27000',
            'cfdi_use' => 'G01',
            'email' => 'ernesto@example.com',
        ]);
        Customer::create(['name' => 'Ing. Meléndez', 'credit_enabled' => true]);
        Customer::create(['name' => 'Alejandro Carrasco']);
    }
}
