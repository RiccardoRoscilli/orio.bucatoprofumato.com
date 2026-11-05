<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Closure\ClosureStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/** Avvisa via email se oggi ci sono scontrini emessi e la chiusura fiscale non e' ancora fatta. */
class CheckDailyClosure extends Command
{
    protected $signature = 'closure:check';

    protected $description = 'Avvisa se manca la chiusura fiscale giornaliera';

    public function handle(ClosureStatus $status): int
    {
        $open = $status->openReceipts()->count();
        $overdue = $status->overdueDay();

        if (! $open) {
            $this->info('Nessuno scontrino da chiudere.');

            return self::SUCCESS;
        }

        $recipients = collect(config('orio.closure.alert_emails'))
            ->merge(User::where('is_admin', true)->where('active', true)->whereNotNull('email')->pluck('email'))
            ->unique()->values()->all();

        $text = ($overdue
                ? "ATTENZIONE: manca la chiusura fiscale del {$overdue->format('d/m/Y')}."
                : 'La chiusura fiscale di oggi non è ancora stata fatta: va eseguita entro mezzanotte.')
            . "\n\nScontrini emessi dopo l'ultima chiusura: {$open}."
            . "\nApri " . route('closure') . " e premi \"Chiudi giornata\".";

        if (! $recipients) {
            Log::warning('Chiusura fiscale mancante ma nessun destinatario configurato (ORIO_CLOSURE_ALERT_EMAILS).', ['scontrini' => $open]);
            $this->warn('Nessun destinatario: imposta ORIO_CLOSURE_ALERT_EMAILS.');

            return self::SUCCESS;
        }

        Mail::raw($text, fn ($m) => $m->to($recipients)->subject('Orio: chiusura fiscale da fare'));
        $this->info('Avviso inviato a ' . implode(', ', $recipients));

        return self::SUCCESS;
    }
}
