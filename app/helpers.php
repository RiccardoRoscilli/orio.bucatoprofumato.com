<?php

if (! function_exists('euro')) {
    function euro(int|float|null $cents): string
    {
        return '€ ' . number_format(($cents ?? 0) / 100, 2, ',', '.');
    }
}
