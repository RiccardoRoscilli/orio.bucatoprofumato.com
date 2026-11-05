<?php

namespace App\Services\Vne;

use RuntimeException;

class VneException extends RuntimeException
{
    /** Codici NACK dell'Appendice I del protocollo Automatic Cash 3.05. */
    public const NACK_MESSAGES = [
        100 => 'Errore nella struttura della richiesta',
        101 => 'Tipo di richiesta non riconosciuto',
        102 => 'Errore generico nella gestione della richiesta',
        103 => 'Errore durante l\'inserimento dello scontrino o del prelievo, riprovare',
        104 => 'Transazione non trovata sulla cassa',
        105 => 'C\'è già uno scontrino in attesa di pagamento',
        106 => 'Taglio non disponibile per il prelievo',
        107 => 'Logout impossibile: pagamento in corso',
        108 => 'Credenziali operatore non valide',
        110 => 'La cassa sta già eseguendo un\'altra operazione',
        113 => 'Impossibile annullare il pagamento POS',
        114 => 'Annullamento non consentito a questo operatore',
        120 => 'Autenticazione fallita (authKey)',
        165 => 'Pagamento non rimborsabile o già rimborsato',
    ];

    public static function fromNack(int|string|null $code): self
    {
        $code = (int) $code;
        $message = self::NACK_MESSAGES[$code] ?? 'Errore sconosciuto';

        return new self("Cassa VNE: {$message} (codice {$code})", $code);
    }
}
