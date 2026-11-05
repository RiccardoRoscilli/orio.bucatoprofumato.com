<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('products:sync')->dailyAt('03:00');

// Se a fine serata ci sono scontrini non chiusi, avvisa via email (la chiusura fiscale scade alle 24)
Schedule::command('closure:check')->dailyAt(config('orio.closure.alert_at'));
