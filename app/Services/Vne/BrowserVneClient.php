<?php

namespace App\Services\Vne;

use Illuminate\Http\Request;

/**
 * Cassa VNE chiamata dal browser del tablet di cassa: Orio gira su internet e non vede la rete
 * del negozio, il tablet si'. Il server prepara la richiesta del protocollo e risponde 428 con
 * il payload; la pagina (resources/js/vne-relay.js) lo manda alla VNE e ripete la stessa
 * chiamata a Orio allegando la risposta. Da li' in poi la logica e' quella di sempre.
 */
class BrowserVneClient extends VneClient
{
    private int $next = 0;

    /** @param list<array{request?:array, response?:array, error?:string}> $relayed */
    public function __construct(private array $relayed = [])
    {
    }

    /** Risposte della VNE allegate dalla pagina: nel corpo (_vne_relay) o, per le GET, nell'header. */
    public static function fromRequest(?Request $request): self
    {
        if (! $request) {
            return new self();
        }
        $relayed = $request->input('_vne_relay');
        if (! is_array($relayed) && ($header = $request->header('X-Vne-Relay'))) {
            $relayed = json_decode((string) base64_decode($header, true), true);
        }

        return new self(is_array($relayed) ? array_values(array_filter($relayed, 'is_array')) : []);
    }

    public static function url(): ?string
    {
        $host = config('orio.vne.host');

        return $host ? "https://{$host}/selfcashapi/" : null;
    }

    protected function send(array $payload): array
    {
        if (! self::url()) {
            throw new VneException('Indirizzo della cassa automatica non configurato (Gestione > Configurazione).');
        }

        $index = $this->next++;
        $entry = $this->relayed[$index] ?? null;

        // Nessuna risposta, o risposta a una richiesta diversa: la pagina deve (ri)mandarla
        if (! $entry || ($entry['request'] ?? null) != $payload) {
            throw new VneRelayRequired(self::url(), $payload, $index);
        }
        if (isset($entry['error'])) {
            throw new \RuntimeException((string) $entry['error']);
        }
        if (! is_array($entry['response'] ?? null)) {
            throw new VneException('Risposta non valida dalla cassa VNE.');
        }

        return $entry['response'];
    }
}
