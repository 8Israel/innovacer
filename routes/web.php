<?php

use App\Http\Controllers\InvoiceDownloadController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect()->route('dashboard') : redirect()->route('login');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::livewire('products', 'pages::products')->name('products.index');
    Route::livewire('customers', 'pages::customers')->name('customers.index');

    Route::livewire('invoices', 'pages::invoices.index')->name('invoices.index');
    Route::livewire('invoices/create', 'pages::invoices.create')->name('invoices.create');
    Route::get('invoices/{invoice}/pdf', [InvoiceDownloadController::class, 'pdf'])->name('invoices.pdf');
    Route::get('invoices/{invoice}/xml', [InvoiceDownloadController::class, 'xml'])->name('invoices.xml');
});

require __DIR__.'/settings.php';
