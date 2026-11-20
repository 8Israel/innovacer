<?php

use App\Http\Controllers\InvoiceDownloadController;
use App\Http\Controllers\SaleDocumentController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect()->route('pos.index') : redirect()->route('login');
})->name('home');

Route::middleware(['auth', 'account.secure'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::livewire('punto-de-venta', 'pages::pos.index')->middleware('permission:sell')->name('pos.index');
    Route::middleware('permission:sell')->group(function () {
        Route::get('ventas/{sale}/ticket', [SaleDocumentController::class, 'ticket'])->name('sales.ticket');
        Route::get('ventas/{sale}/nota', [SaleDocumentController::class, 'note'])->name('sales.note');
    });
    Route::livewire('caja', 'pages::cash.index')->name('cash.index');

    Route::middleware('permission:manage_inventory')->group(function () {
        Route::livewire('inventario', 'pages::inventory.index')->name('inventory.index');
        Route::livewire('inventario/entrada', 'pages::inventory.entry')->name('inventory.entry');
        Route::livewire('inventario/traspaso', 'pages::inventory.transfer')->name('inventory.transfer');
        Route::livewire('inventario/prestamos', 'pages::inventory.loans')->name('inventory.loans');
        Route::livewire('inventario/productos/{product}/kardex', 'pages::inventory.kardex')->name('inventory.kardex');
    });

    Route::livewire('products', 'pages::products')->name('products.index');
    Route::livewire('customers', 'pages::customers')->name('customers.index');

    Route::livewire('invoices', 'pages::invoices.index')->name('invoices.index');
    Route::livewire('invoices/create', 'pages::invoices.create')->name('invoices.create');
    Route::get('invoices/{invoice}/pdf', [InvoiceDownloadController::class, 'pdf'])->name('invoices.pdf');
    Route::get('invoices/{invoice}/xml', [InvoiceDownloadController::class, 'xml'])->name('invoices.xml');
});

require __DIR__.'/settings.php';
