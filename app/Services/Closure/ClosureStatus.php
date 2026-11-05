<?php

namespace App\Services\Closure;

use App\Models\CashClosure;
use App\Models\Sale;
use Illuminate\Support\Carbon;

/**
 * Stato della chiusura fiscale giornaliera. La chiusura RT va fatta entro le 24 del giorno in cui
 * e' stato emesso lo scontrino: ogni scontrino emesso dopo l'ultima chiusura fiscale e' "aperto".
 */
class ClosureStatus
{
    public function lastFiscalClosure(): ?CashClosure
    {
        return CashClosure::whereNotNull('fiscal_closed_at')->latest('fiscal_closed_at')->first();
    }

    /** Scontrini emessi dopo l'ultima chiusura fiscale. */
    public function openReceipts()
    {
        $last = $this->lastFiscalClosure()?->fiscal_closed_at;

        return Sale::where('status', 'scontrinata')
            ->when($last, fn ($q) => $q->where('closed_at', '>', $last));
    }

    /** Primo giorno passato con scontrini non chiusi: se c'e', la chiusura e' stata dimenticata. */
    public function overdueDay(): ?Carbon
    {
        $first = $this->openReceipts()->where('closed_at', '<', today())->min('closed_at');

        return $first ? Carbon::parse($first)->startOfDay() : null;
    }

    public function fiscalClosedToday(): bool
    {
        return CashClosure::whereNotNull('fiscal_closed_at')
            ->where('fiscal_closed_at', '>=', today())
            ->exists() && ! $this->openReceipts()->where('closed_at', '>=', today())->exists();
    }

    /**
     * Avviso del giorno: dall'ora impostata, se oggi la chiusura fiscale non e' ancora fatta
     * (o sono stati emessi altri scontrini dopo l'ultima chiusura).
     */
    public function reminderDue(): bool
    {
        return now()->format('H:i') >= config('orio.closure.reminder_at') && ! $this->fiscalClosedToday();
    }

    /** Messaggio di blocco per i nuovi scontrini, o null se si puo' emettere. */
    public function receiptBlockMessage(): ?string
    {
        $day = $this->overdueDay();

        return $day
            ? "Manca la chiusura fiscale del {$day->format('d/m/Y')}: fai prima la chiusura giornata, poi potrai emettere scontrini."
            : null;
    }
}
