<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClosureController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\Dev\FakeAxonController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\FiscalPrinterController;
use App\Http\Controllers\OpenApiCallController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/', [SaleController::class, 'cassa'])->name('cassa');

    Route::post('/assistenza', [\App\Http\Controllers\SupportRequestController::class, 'store'])->name('support.request');
    Route::get('/vendite', [SaleController::class, 'index'])->name('sales.index');
    Route::post('/vendite', [SaleController::class, 'store'])->name('sales.store');
    Route::put('/vendite/bozza', [SaleController::class, 'saveDraft'])->name('sales.draft');
    Route::get('/vendite/{sale}', [SaleController::class, 'show'])->name('sales.show');
    Route::post('/vendite/{sale}/annulla', [SaleController::class, 'cancel'])->name('sales.cancel');
    Route::post('/vendite/{sale}/storno', [\App\Http\Controllers\SaleVoidController::class, 'start'])->name('sales.void');
    Route::post('/vendite/{sale}/storno/conferma', [\App\Http\Controllers\SaleVoidController::class, 'confirm'])->name('sales.void.confirm');

    Route::post('/vendite/{sale}/pagamento', [PaymentController::class, 'start'])->name('payments.start');
    Route::get('/vendite/{sale}/pagamento', [PaymentController::class, 'poll'])->name('payments.poll');
    Route::post('/vendite/{sale}/pagamento/annulla', [PaymentController::class, 'cancel'])->name('payments.cancel');
    Route::post('/vendite/{sale}/pagamento/scenario', [PaymentController::class, 'scenario'])->name('payments.scenario');

    Route::post('/vendite/{sale}/scontrino', [DocumentController::class, 'receipt'])->name('sales.receipt.emit');
    Route::post('/vendite/{sale}/scontrino/conferma', [DocumentController::class, 'receiptConfirm'])->name('sales.receipt.confirm');
    Route::get('/vendite/{sale}/scontrino', [DocumentController::class, 'receiptPreview'])->name('sales.receipt');
    Route::post('/vendite/{sale}/fattura', [DocumentController::class, 'invoice'])->name('sales.invoice');
    Route::get('/vendite/{sale}/fattura.pdf', [DocumentController::class, 'invoicePdf'])->name('sales.invoice.pdf');

    Route::post('/presenze/badge', [\App\Http\Controllers\AttendanceController::class, 'badge'])->name('attendances.badge');
    Route::post('/presenze/abilita', [\App\Http\Controllers\AttendanceDeviceController::class, 'activate'])->middleware('throttle:5,1')->name('devices.activate');

    Route::get('/clienti/piva/{vat}', [CustomerController::class, 'lookupVat'])->name('customers.lookup');
    Route::post('/clienti', [CustomerController::class, 'store'])->name('customers.store');
    Route::get('/clienti', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('/clienti/dati', [CustomerController::class, 'data'])->name('customers.data');
    Route::get('/clienti/cerca', [CustomerController::class, 'search'])->name('customers.search');

    Route::get('/prodotti', [ProductController::class, 'index'])->name('products.index');
    Route::get('/prodotti/dati', [ProductController::class, 'data'])->name('products.data');
    Route::get('/prodotti/cerca', [ProductController::class, 'search'])->name('products.search');
    Route::get('/prodotti/barcode/{code}', [ProductController::class, 'barcode'])->name('products.barcode');
    Route::get('/prodotti/{product}', [ProductController::class, 'show'])->whereNumber('product')->name('products.show');

    Route::get('/magazzino', [StockController::class, 'index'])->name('stock.index');
    Route::post('/magazzino/carico', [StockController::class, 'load'])->name('stock.load');
    Route::get('/magazzino/excel', [StockController::class, 'excelTemplate'])->name('stock.excel');
    Route::post('/magazzino/excel', [StockController::class, 'excelImport'])->name('stock.excel.import');

    // Riassortimento: l'ordine a TEA lo prepara e lo invia anche l'operatrice, e spunta la merce in arrivo
    Route::get('/ordini-tea', [\App\Http\Controllers\TeaOrderController::class, 'index'])->name('tea-orders.index');
    Route::post('/ordini-tea', [\App\Http\Controllers\TeaOrderController::class, 'store'])->name('tea-orders.store');
    Route::get('/ordini-tea/modello', [\App\Http\Controllers\TeaOrderController::class, 'orderTemplate'])->name('tea-orders.template');
    Route::post('/ordini-tea/excel', [\App\Http\Controllers\TeaOrderController::class, 'orderImport'])->name('tea-orders.import');
    Route::get('/ordini-tea/{teaOrder}', [\App\Http\Controllers\TeaOrderController::class, 'show'])->whereNumber('teaOrder')->name('tea-orders.show');
    Route::delete('/ordini-tea/{teaOrder}', [\App\Http\Controllers\TeaOrderController::class, 'destroy'])->whereNumber('teaOrder')->name('tea-orders.destroy');
    Route::post('/ordini-tea/{teaOrder}/modifica', [\App\Http\Controllers\TeaOrderController::class, 'reopen'])->name('tea-orders.reopen');
    Route::post('/ordini-tea/{teaOrder}/invia', [\App\Http\Controllers\TeaOrderController::class, 'resend'])->name('tea-orders.resend');
    Route::post('/ordini-tea/ddt/{ddt}/conferma', [\App\Http\Controllers\TeaOrderController::class, 'confirmDdt'])->name('tea-ddts.confirm');
    Route::post('/ordini-tea/ddt-righe/{item}', [\App\Http\Controllers\TeaOrderController::class, 'receive'])->name('tea-ddt-items.receive');
    Route::post('/ordini-tea/{teaOrder}/stato', [\App\Http\Controllers\TeaOrderController::class, 'refresh'])->name('tea-orders.refresh');
    Route::post('/ordini-tea/arrivi', [\App\Http\Controllers\TeaOrderController::class, 'arrivals'])->name('tea-orders.arrivals');
    Route::put('/ordini-tea/in-preparazione', [\App\Http\Controllers\TeaOrderController::class, 'saveOpen'])->name('tea-orders.save-open');

    Route::middleware('can:admin')->group(function () {
        // Riassortimento: ordini a TEA (teaerp) e scorte minime
        Route::get('/ordini-tea/scorte-minime', [\App\Http\Controllers\TeaOrderController::class, 'minimumTemplate'])->name('tea-orders.minimum');
        Route::post('/ordini-tea/scorte-minime', [\App\Http\Controllers\TeaOrderController::class, 'minimumImport'])->name('tea-orders.minimum.import');
        Route::patch('/prodotti/{product}/scorta-minima', [\App\Http\Controllers\TeaOrderController::class, 'updateMinimum'])->name('products.minimum');
        Route::get('/magazzino/inventario', [StockController::class, 'index'])->name('stock.inventory');
        Route::post('/magazzino/inventario', [StockController::class, 'adjust'])->name('stock.adjust');

        Route::get('/prodotti/nuovo', [ProductController::class, 'create'])->name('products.create');
        Route::post('/prodotti', [ProductController::class, 'store'])->name('products.store');
        Route::get('/prodotti/{product}/modifica', [ProductController::class, 'edit'])->name('products.edit');
        Route::put('/prodotti/{product}', [ProductController::class, 'update'])->name('products.update');
        Route::post('/prodotti/sincronizza', [ProductController::class, 'sync'])->name('products.sync');
        Route::post('/prodotti/allinea-prezzi', [ProductController::class, 'alignPrice'])->name('products.align-all');
        Route::post('/prodotti/{product}/allinea-prezzo', [ProductController::class, 'alignPrice'])->name('products.align');
        Route::patch('/prodotti/{product}/prezzo', [ProductController::class, 'updatePrice'])->name('products.price');
        Route::patch('/prodotti/{product}/negozio', [ProductController::class, 'updateShop'])->name('products.shop');

        Route::get('/utenti', [UserController::class, 'index'])->name('users.index');
        Route::get('/badge', [\App\Http\Controllers\BadgeController::class, 'index'])->name('badges.index');
        Route::post('/badge', [\App\Http\Controllers\BadgeController::class, 'store'])->name('badges.store');
        Route::put('/badge/{badge}', [\App\Http\Controllers\BadgeController::class, 'update'])->name('badges.update');
        Route::post('/badge/dispositivi', [\App\Http\Controllers\AttendanceDeviceController::class, 'enableThis'])->name('devices.enable');
        Route::post('/badge/dispositivi/codice', [\App\Http\Controllers\AttendanceDeviceController::class, 'code'])->name('devices.code');
        Route::delete('/badge/dispositivi/{device}', [\App\Http\Controllers\AttendanceDeviceController::class, 'revoke'])->name('devices.revoke');

        Route::get('/presenze', [\App\Http\Controllers\AttendanceController::class, 'index'])->name('attendances.index');
        Route::get('/presenze/excel', [\App\Http\Controllers\AttendanceController::class, 'excel'])->name('attendances.excel');
        Route::post('/presenze', [\App\Http\Controllers\AttendanceController::class, 'store'])->name('attendances.store');
        Route::put('/presenze/{attendance}', [\App\Http\Controllers\AttendanceController::class, 'update'])->name('attendances.update');
        Route::delete('/presenze/{attendance}', [\App\Http\Controllers\AttendanceController::class, 'destroy'])->name('attendances.destroy');
        Route::post('/utenti', [UserController::class, 'store'])->name('users.store');
        Route::put('/utenti/{user}', [UserController::class, 'update'])->name('users.update');

        Route::get('/stampante', [FiscalPrinterController::class, 'index'])->name('fiscal-printer');

        Route::get('/configurazione', [\App\Http\Controllers\SettingsController::class, 'index'])->name('settings');
        Route::put('/configurazione', [\App\Http\Controllers\SettingsController::class, 'update'])->name('settings.update');
        Route::post('/configurazione/prova-pos', [\App\Http\Controllers\SettingsController::class, 'testPos'])->name('settings.test-pos');
        Route::post('/configurazione/prova-vne', [\App\Http\Controllers\SettingsController::class, 'testVne'])->name('settings.test-vne');
        Route::post('/configurazione/prova-tea', [\App\Http\Controllers\SettingsController::class, 'testTea'])->name('settings.test-tea');

        Route::get('/openapi', [OpenApiCallController::class, 'index'])->name('openapi-calls');
        Route::get('/openapi/csv', [OpenApiCallController::class, 'csv'])->name('openapi-calls.csv');
    });

    Route::get('/chiusura', [ClosureController::class, 'index'])->name('closure');
    Route::post('/chiusura/fiscale', [ClosureController::class, 'fiscal'])->name('closure.fiscal');
    Route::post('/chiusura/fiscale/conferma', [ClosureController::class, 'fiscalConfirm'])->name('closure.fiscal.confirm');
    Route::post('/chiusura/automatica', [ClosureController::class, 'autoStart'])->name('closure.auto');
    Route::post('/chiusura/automatica/esito', [ClosureController::class, 'autoResult'])->name('closure.auto.result');
    Route::post('/chiusura/stato-cassa', [ClosureController::class, 'vneStatus'])->name('closure.vne-status');
    Route::post('/chiusura/{closure}/vne', [ClosureController::class, 'vne'])->name('closure.vne');
});

// Simulatore della stampante fiscale Axon DADO (solo in locale)
if (app()->isLocal()) {
    Route::get('/dev/axon/_io', [FakeAxonController::class, 'io']);
}
