<?php

use App\Http\Controllers\CustomerStatementController;
use App\Http\Controllers\InvoiceDownloadController;
use App\Http\Controllers\ProductLabelController;
use App\Http\Controllers\SaleDocumentController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect()->route('pos.index') : redirect()->route('login');
})->name('home');

Route::middleware(['auth', 'account.secure'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::livewire('punto-de-venta', 'pages::pos.index')->middleware('permission:sell')->name('pos.index');
    Route::middleware('permission:sell')->group(function () {
        Route::livewire('ventas', 'pages::sales.index')->name('sales.index');
        Route::livewire('ventas/{sale}', 'pages::sales.show')->name('sales.show');
        Route::get('ventas/{sale}/ticket', [SaleDocumentController::class, 'ticket'])->name('sales.ticket');
        Route::get('ventas/{sale}/nota', [SaleDocumentController::class, 'note'])->name('sales.note');
    });
    Route::livewire('caja', 'pages::cash.index')->name('cash.index');

    Route::middleware('permission:manage_inventory')->group(function () {
        Route::livewire('inventario', 'pages::inventory.index')->name('inventory.index');
        Route::livewire('inventario/entrada', 'pages::inventory.entry')->name('inventory.entry');
        Route::livewire('inventario/traspaso', 'pages::inventory.transfer')->name('inventory.transfer');
        Route::livewire('inventario/prestamos', 'pages::inventory.loans')->name('inventory.loans');
        Route::livewire('inventario/conteo', 'pages::inventory.count')->name('inventory.count');
        Route::livewire('inventario/importar', 'pages::inventory.import')->name('inventory.import');
        Route::livewire('inventario/etiquetas', 'pages::inventory.labels')->name('inventory.labels');
        Route::get('inventario/etiquetas/imprimir', ProductLabelController::class)->name('products.labels');
        Route::livewire('inventario/productos/{product}/kardex', 'pages::inventory.kardex')->name('inventory.kardex');
    });

    Route::livewire('products', 'pages::products')->name('products.index');
    Route::livewire('clientes', 'pages::customers.index')->name('customers.index');
    Route::livewire('clientes/nuevo', 'pages::customers.show')->name('customers.create');
    Route::livewire('clientes/{customer}', 'pages::customers.show')->name('customers.show');
    Route::livewire('clientes/{customer}/estado-de-cuenta', 'pages::customers.statement')->name('customers.statement');
    Route::get('clientes/{customer}/estado-de-cuenta/imprimir', CustomerStatementController::class)->name('customers.statement.print');

    Route::livewire('proveedores', 'pages::suppliers.index')->name('suppliers.index');
    Route::livewire('proveedores/{supplier}', 'pages::suppliers.show')->name('suppliers.show');

    Route::livewire('reportes', 'pages::reports.index')->middleware('permission:view_reports')->name('reports.index');

    Route::livewire('configuracion', 'pages::configuration.index')->middleware('permission:manage_settings')->name('configuration.index');

    Route::livewire('invoices', 'pages::invoices.index')->name('invoices.index');
    Route::livewire('facturas/nueva', 'pages::invoices.from-sales')->middleware('permission:invoice')->name('invoices.from-sales');
    Route::livewire('invoices/create', 'pages::invoices.create')->name('invoices.create');
    Route::get('invoices/{invoice}/pdf', [InvoiceDownloadController::class, 'pdf'])->name('invoices.pdf');
    Route::get('invoices/{invoice}/xml', [InvoiceDownloadController::class, 'xml'])->name('invoices.xml');
});

require __DIR__.'/settings.php';
