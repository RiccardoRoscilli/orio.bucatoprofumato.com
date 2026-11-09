<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClosureController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\Dev\FakeAxonController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\FiscalPrinterController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/', [SaleController::class, 'cassa'])->name('cassa');

    Route::get('/vendite', [SaleController::class, 'index'])->name('sales.index');
    Route::post('/vendite', [SaleController::class, 'store'])->name('sales.store');
    Route::put('/vendite/bozza', [SaleController::class, 'saveDraft'])->name('sales.draft');
    Route::get('/vendite/{sale}', [SaleController::class, 'show'])->name('sales.show');
    Route::post('/vendite/{sale}/annulla', [SaleController::class, 'cancel'])->name('sales.cancel');

    Route::post('/vendite/{sale}/pagamento', [PaymentController::class, 'start'])->name('payments.start');
    Route::get('/vendite/{sale}/pagamento', [PaymentController::class, 'poll'])->name('payments.poll');
    Route::post('/vendite/{sale}/pagamento/annulla', [PaymentController::class, 'cancel'])->name('payments.cancel');
    Route::post('/vendite/{sale}/pagamento/scenario', [PaymentController::class, 'scenario'])->name('payments.scenario');

    Route::post('/vendite/{sale}/scontrino', [DocumentController::class, 'receipt'])->name('sales.receipt.emit');
    Route::post('/vendite/{sale}/scontrino/conferma', [DocumentController::class, 'receiptConfirm'])->name('sales.receipt.confirm');
    Route::get('/vendite/{sale}/scontrino', [DocumentController::class, 'receiptPreview'])->name('sales.receipt');
    Route::post('/vendite/{sale}/fattura', [DocumentController::class, 'invoice'])->name('sales.invoice');
    Route::get('/vendite/{sale}/fattura.pdf', [DocumentController::class, 'invoicePdf'])->name('sales.invoice.pdf');

    Route::get('/clienti/piva/{vat}', [CustomerController::class, 'lookupVat'])->name('customers.lookup');
    Route::post('/clienti', [CustomerController::class, 'store'])->name('customers.store');
    Route::get('/clienti', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('/clienti/dati', [CustomerController::class, 'data'])->name('customers.data');
    Route::get('/clienti/cerca', [CustomerController::class, 'search'])->name('customers.search');

    Route::get('/prodotti', [ProductController::class, 'index'])->name('products.index');
    Route::get('/prodotti/dati', [ProductController::class, 'data'])->name('products.data');
    Route::get('/prodotti/cerca', [ProductController::class, 'search'])->name('products.search');
    Route::get('/prodotti/barcode/{code}', [ProductController::class, 'barcode'])->name('products.barcode');

    Route::middleware('can:admin')->group(function () {
        Route::post('/prodotti/sincronizza', [ProductController::class, 'sync'])->name('products.sync');
        Route::post('/prodotti/allinea-prezzi', [ProductController::class, 'alignPrice'])->name('products.align-all');
        Route::post('/prodotti/{product}/allinea-prezzo', [ProductController::class, 'alignPrice'])->name('products.align');
        Route::patch('/prodotti/{product}/prezzo', [ProductController::class, 'updatePrice'])->name('products.price');

        Route::get('/utenti', [UserController::class, 'index'])->name('users.index');
        Route::post('/utenti', [UserController::class, 'store'])->name('users.store');
        Route::put('/utenti/{user}', [UserController::class, 'update'])->name('users.update');

        Route::get('/stampante', [FiscalPrinterController::class, 'index'])->name('fiscal-printer');
    });

    Route::get('/chiusura', [ClosureController::class, 'index'])->name('closure');
    Route::post('/chiusura/fiscale', [ClosureController::class, 'fiscal'])->name('closure.fiscal');
    Route::post('/chiusura/fiscale/conferma', [ClosureController::class, 'fiscalConfirm'])->name('closure.fiscal.confirm');
    Route::post('/chiusura/{closure}/vne', [ClosureController::class, 'vne'])->name('closure.vne');
});

// Simulatore della stampante fiscale Axon DADO (solo in locale)
if (app()->isLocal()) {
    Route::get('/dev/axon/_io', [FakeAxonController::class, 'io']);
}
