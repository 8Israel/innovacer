<?php

use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Hash;

test('it loads the demo data from the screen designs', function () {
    $this->seed(DemoSeeder::class);

    $mostrador = Warehouse::query()->where('code', 'MOS')->sole();
    $consigna = Warehouse::query()->where('code', 'CFR')->sole();
    $nitrogeno = Product::query()->where('sku', 'UNI-20')->sole();

    expect(stockOf($nitrogeno, $mostrador))->toBe('12.000');
    expect(stockOf($nitrogeno, $consigna))->toBe('25.000');
    expect($consigna->lender->name)->toBe('Francisco Rodríguez');
    expect($mostrador->stocks()->whereHas('lot', fn ($lot) => $lot->where('owner_supplier_id', '!=', null))->exists())->toBeTrue();
    expect(Product::query()->where('sku', 'MAN-16')->sole()->unit_price)->toBeNull();
    expect(InventoryMovement::count())->toBe(11);
    expect(Customer::query()->where('name', 'Francisco Castañón')->sole()->destinations()->pluck('name')->all())->toBe(['Reynosa', 'Jalincher']);
    expect(Customer::query()->where('name', 'Ernesto Castaño')->sole()->credit_limit)->toBe('50000.00');
    expect(Customer::query()->where('name', 'Alejandro Carrasco')->sole()->hasCredit())->toBeFalse();
});

test('it creates the owner and the clerk with a temporary password', function () {
    $this->seed(DemoSeeder::class);

    $users = User::query()->orderBy('id')->get();

    expect($users->map(fn (User $user) => [$user->email, $user->role, $user->must_change_password])->all())->toBe([
        ['dueno@innovacer.test', UserRole::Owner, true],
        ['encargada@innovacer.test', UserRole::Clerk, true],
    ]);
    expect(Hash::check(DemoSeeder::TEMPORARY_PASSWORD, $users->first()->password))->toBeTrue();
});

test('it does nothing when the demo data is already loaded', function () {
    $this->seed(DemoSeeder::class);

    $this->seed(DemoSeeder::class);

    expect(Warehouse::count())->toBe(3);
    expect(User::count())->toBe(2);
});
