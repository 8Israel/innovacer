<?php

use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

test('only the owner can open the configuration', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('configuration.index'))
        ->assertRedirect(route('pos.index'));

    $this->actingAs(User::factory()->owner()->create())
        ->get(route('configuration.index'))
        ->assertOk()
        ->assertSeeInOrder(['Usuarios', 'Qué puede hacer cada rol', 'Almacenes', 'Datos del negocio'])
        ->assertSeeInOrder(['Cancelar ventas', 'Sí', 'No', 'Pagar a proveedores', 'Sí', 'No']);
});

test('it creates a user with a temporary password that must be changed', function () {
    Livewire::actingAs(User::factory()->owner()->create())
        ->test('pages::configuration.index')
        ->call('createUser')
        ->set('user_name', 'Lupita')
        ->set('user_email', 'lupita@innovacer.test')
        ->set('temporary_password', 'Laguna-7315')
        ->call('saveUser')
        ->assertHasNoErrors()
        ->assertSee('lupita@innovacer.test');

    $user = User::query()->where('email', 'lupita@innovacer.test')->sole();
    expect($user->role)->toBe(UserRole::Clerk);
    expect($user->must_change_password)->toBeTrue();
    expect(Hash::check('Laguna-7315', $user->password))->toBeTrue();
});

test('resetting a password logs the user out everywhere', function () {
    $clerk = User::factory()->create();
    openSession($clerk, 'phone-session');

    Livewire::actingAs(User::factory()->owner()->create())
        ->test('pages::configuration.index')
        ->call('startPasswordReset', $clerk->id)
        ->set('temporary_password', 'Nogal-1234')
        ->call('resetPassword')
        ->assertHasNoErrors();

    $clerk->refresh();
    expect($clerk->must_change_password)->toBeTrue();
    expect(Hash::check('Nogal-1234', $clerk->password))->toBeTrue();
    expect(DB::table('sessions')->where('user_id', $clerk->id)->exists())->toBeFalse();
});

test('deactivating a user closes their sessions', function () {
    $clerk = User::factory()->create();
    openSession($clerk, 'counter-session');

    Livewire::actingAs(User::factory()->owner()->create())
        ->test('pages::configuration.index')
        ->call('editUser', $clerk->id)
        ->set('user_active', false)
        ->call('saveUser')
        ->assertHasNoErrors();

    expect($clerk->fresh()->is_active)->toBeFalse();
    expect(DB::table('sessions')->where('user_id', $clerk->id)->exists())->toBeFalse();
});

test('an owner cannot deactivate or demote themselves, but can demote another owner', function () {
    $owner = User::factory()->owner()->create();
    $otherOwner = User::factory()->owner()->create();

    Livewire::actingAs($owner)
        ->test('pages::configuration.index')
        ->call('editUser', $owner->id)
        ->set('user_active', false)
        ->call('saveUser')
        ->assertHasErrors('user')
        ->set('user_active', true)
        ->set('user_role', 'clerk')
        ->call('saveUser')
        ->assertHasErrors('user')
        ->call('editUser', $otherOwner->id)
        ->set('user_role', 'clerk')
        ->call('saveUser')
        ->assertHasNoErrors();

    expect($owner->fresh()->only(['role', 'is_active']))->toBe(['role' => UserRole::Owner, 'is_active' => true]);
    expect($otherOwner->fresh()->role)->toBe(UserRole::Clerk);
});

test('it adds a warehouse lent by a supplier', function () {
    $francisco = Supplier::factory()->inKind()->create(['name' => 'Francisco Rodríguez']);

    Livewire::actingAs(User::factory()->owner()->create())
        ->test('pages::configuration.index')
        ->call('createWarehouse')
        ->set('warehouse_name', 'Consigna Francisco')
        ->set('warehouse_code', 'cfr')
        ->set('warehouse_lender_id', (string) $francisco->id)
        ->call('saveWarehouse')
        ->assertHasNoErrors()
        ->assertSee('Dueño: Francisco Rodríguez · préstamos que se reponen');

    expect(Warehouse::query()->where('code', 'CFR')->sole()->only(['lender_id', 'is_sellable']))
        ->toBe(['lender_id' => $francisco->id, 'is_sellable' => false]);
});

test('a warehouse with stock keeps its owner and stays active', function () {
    $warehouse = Warehouse::factory()->create();
    stockLot(Product::factory()->create(), $warehouse, '5', '2026-10-01');

    Livewire::actingAs(User::factory()->owner()->create())
        ->test('pages::configuration.index')
        ->call('editWarehouse', $warehouse->id)
        ->set('warehouse_lender_id', (string) Supplier::factory()->inKind()->create()->id)
        ->call('saveWarehouse')
        ->assertHasErrors('warehouse_lender_id')
        ->set('warehouse_lender_id', '')
        ->set('warehouse_active', false)
        ->call('saveWarehouse')
        ->assertHasErrors('warehouse_active');

    expect($warehouse->fresh()->only(['lender_id', 'is_active']))->toBe(['lender_id' => null, 'is_active' => true]);
});

test('it saves the business data shown on the documents', function () {
    Livewire::actingAs(User::factory()->owner()->create())
        ->test('pages::configuration.index')
        ->set('business_name', 'Innovacer Agrotechnology')
        ->set('business_address', 'Carretera a Reynosa km 4')
        ->set('business_rfc', 'xyz')
        ->call('saveBusiness')
        ->assertHasErrors('business_rfc')
        ->set('business_rfc', 'iag2001019a1')
        ->set('ticket_printer_width', '58')
        ->call('saveBusiness')
        ->assertHasNoErrors();

    expect(setting('business_address'))->toBe('Carretera a Reynosa km 4');
    expect(setting('business_rfc'))->toBe('IAG2001019A1');
    expect(setting('ticket_printer_width'))->toBe('58');
});
