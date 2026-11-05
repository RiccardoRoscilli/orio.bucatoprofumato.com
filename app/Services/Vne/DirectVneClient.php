<?php

namespace App\Services\Vne;

use Illuminate\Support\Facades\Http;

/**
 * Chiamata diretta dal server di orio alla cassa: funziona solo quando orio gira nella
 * stessa LAN della VNE (sviluppo locale con AC_SIM, oppure il ponte in negozio).
 */
class DirectVneClient extends VneClient
{
    protected function send(array $payload): array
    {
        $host = config('orio.vne.host');

        if (! $host) {
            throw new VneException('Indirizzo della cassa VNE non configurato (VNE_HOST)');
        }

        // Certificato self-signed sulla macchina: niente verifica. Il protocollo chiede di
        // ripetere la richiesta se non arriva risposta entro 5 secondi.
        $response = Http::withoutVerifying()
            ->timeout(config('orio.vne.timeout'))
            ->retry(2, 200, throw: false)
            ->asJson()
            ->post("https://{$host}/selfcashapi/", $payload);

        $json = $response->json();

        if (! is_array($json)) {
            throw new VneException('Risposta non valida dalla cassa VNE: ' . mb_substr($response->body(), 0, 200));
        }

        return $json;
    }
}
