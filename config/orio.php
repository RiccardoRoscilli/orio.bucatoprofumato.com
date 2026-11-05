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
        // fake = anteprima senza stampare, epson = FPMate chiamato dal browser della postazione
        'driver' => env('FISCAL_PRINTER_DRIVER', 'fake'),
        'host' => env('FISCAL_PRINTER_HOST'),
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
