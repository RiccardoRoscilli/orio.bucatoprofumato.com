<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Finora Orio era sempre e solo il chiamante verso teaerp (vedi TeaOrderClient):
| questa è la prima e unica rotta nel verso opposto. Serve per i cesti natalizi,
| che TEA fattura e spedisce di propria iniziativa senza che Orio faccia un
| ordine: senza questa rotta non ci sarebbe nessun ordine locale da spuntare
| quando arriva la merce. Autenticazione a parte (header condiviso, vedi
| TeaCestiOrderController), non c'è nessun'altra API verso l'esterno qui.
|
*/

Route::post('/tea/cesti-orders', [\App\Http\Controllers\Api\TeaCestiOrderController::class, 'store']);
