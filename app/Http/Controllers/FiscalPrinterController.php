<?php

namespace App\Http\Controllers;

/** Impostazioni > Stampante: configurazione attuale e prove di comunicazione dal tablet. */
class FiscalPrinterController extends Controller
{
    public function index()
    {
        $config = config('orio.fiscal_printer');

        return view('fiscal-printer', [
            'config' => $config,
            'base' => $config['host'] ? 'http://' . $config['host'] : null,
        ]);
    }
}
