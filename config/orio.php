<?php

return [
    'vne' => [
        // fake = cassa simulata in orio, direct = chiamata dal server a https://VNE_HOST/selfcashapi/
        'driver' => env('VNE_DRIVER', 'fake'),
        'host' => env('VNE_HOST'),
        'op_name' => env('VNE_OP_NAME', 'orio'),
        'timeout' => 5,
    ],

    'fiscal_printer' => [
        // fake = anteprima senza stampare, axon = Axon DADO RT (WyCash 600) con le API WEB G100,
        // epson = FPMate. axon ed epson sono chiamate dal browser della postazione.
        'driver' => env('FISCAL_PRINTER_DRIVER', 'fake'),
        'host' => env('FISCAL_PRINTER_HOST'),
        // Axon: aliquota IVA => reparto programmato sulla stampante (es. "22:1,10:2,5:3,4:4")
        'departments' => collect(explode(',', (string) env('FISCAL_PRINTER_DEPARTMENTS', '22:1,10:2,5:3,4:4')))
            ->mapWithKeys(fn ($pair) => [(int) strtok($pair, ':') => (int) strtok(':')])->all(),
        // Axon: numeri di pagamento programmati sulla stampante
        'payment_cash' => (int) env('FISCAL_PRINTER_PAYMENT_CASH', 1),
        'payment_card' => (int) env('FISCAL_PRINTER_PAYMENT_CARD', 2),
    ],

    'closure' => [
        // Da quest'ora, se la chiusura fiscale di oggi non e' fatta, compare l'avviso rosso
        'reminder_at' => env('ORIO_CLOSURE_REMINDER_AT', '19:00'),
        // A quest'ora parte l'email se ci sono scontrini del giorno non ancora chiusi
        'alert_at' => env('ORIO_CLOSURE_ALERT_AT', '21:00'),
        // Destinatari dell'email, separati da virgola (oltre agli admin con email)
        'alert_emails' => array_filter(array_map('trim', explode(',', (string) env('ORIO_CLOSURE_ALERT_EMAILS', '')))),
    ],

    'fattura24' => [
        'api_key' => env('FATTURA24_API_KEY'),
        'id_numerator' => env('FATTURA24_ID_NUMERATOR'),
        'base_uri' => 'https://www.app.fattura24.com/api/v0.3/',
    ],

    'openapi' => [
        'live' => env('OPENAPI_ENV', 'SANDBOX') === 'LIVE',
        'email' => env('OPENAPI_EMAIL'),
        'password' => env('OPENAPI_PASSWORD'),
        // Prezzo per chiamata da rifatturare al cliente, in centesimi IVA esclusa
        'price_cents' => (int) env('OPENAPI_PRICE_CENTS', 0),
    ],

    'bucato' => [
        // Export catalogo di bucatoprofumato.com (stesso endpoint di emmycasa)
        'products_csv_url' => env('BUCATO_PRODUCTS_CSV_URL', 'https://www.bucatoprofumato.com/productexport/index/export/'),
    ],

    'company' => [
        'name' => env('COMPANY_NAME', 'LE ESSENZE DI ELDA SRL'),
        'address' => env('COMPANY_ADDRESS', ''),
        'vat_code' => env('COMPANY_VAT_CODE', ''),
    ],
];
