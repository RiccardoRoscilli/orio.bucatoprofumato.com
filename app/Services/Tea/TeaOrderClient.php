<?php

namespace App\Services\Tea;

use App\Models\TeaOrder;
use Illuminate\Support\Facades\Http;

/**
 * Invio degli ordini di riassortimento a teaerp (POST /api/orio/orders) e lettura dello stato.
 * teaerp crea un ordine TEA normale (orio-000001) intestato a Le Essenze di Elda SRL, con i
 * prezzi del listino d'acquisto e ritiro in sede; lo stesso riferimento non crea mai doppioni.
 */
class TeaOrderClient
{
    public function send(TeaOrder $order): array
    {
        $order->loadMissing('items');

        return $this->request('post', '/api/orio/orders', [
            'reference' => $order->reference,
            'shipping' => config('orio.tea.shipping'),
            'items' => $order->items->map(fn ($i) => ['sku' => $i->sku, 'qty' => $i->qty])->values()->all(),
        ]);
    }

    public function status(TeaOrder $order): array
    {
        return $this->request('get', '/api/orio/orders/' . rawurlencode($order->reference));
    }

    /**
     * Pezzi per scatola di ogni prodotto TEA (servizio product-boxes di teaerp per ordinare a
     * scatole), per SKU. Tenuto un'ora in memoria: cambia di rado.
     *
     * @return array<string, int>
     * @throws \RuntimeException se teaerp non risponde
     */
    public function productBoxes(): array
    {
        return \Illuminate\Support\Facades\Cache::remember('tea.product_boxes', now()->addHour(), function () {
            $result = $this->request('get', '/api/emmycasa/product-boxes');
            if (! $result['ok']) {
                throw new \RuntimeException('teaerp non ha dato i pezzi per scatola: ' . $result['message']);
            }

            return collect($result['data']['products'] ?? [])
                ->filter(fn ($p) => ($p['sku'] ?? '') !== '' && (int) ($p['pezzi_per_box'] ?? 0) > 0)
                ->mapWithKeys(fn ($p) => [trim($p['sku']) => (int) $p['pezzi_per_box']])
                ->all();
        });
    }

    /** Prova delle credenziali senza creare ordini: un riferimento inesistente deve dare 404. */
    public function ping(): array
    {
        $result = $this->request('get', '/api/orio/orders/ORIO-PROVA-COLLEGAMENTO');
        if ($result['status'] === 404) {
            return ['ok' => true, 'message' => "Collegamento riuscito: teaerp riconosce l'utente di Orio."];
        }
        if (in_array($result['status'], [401, 403], true)) {
            return ['ok' => false, 'message' => 'Utente o password non accettati da teaerp.'];
        }

        return ['ok' => false, 'message' => $result['message'] ?? 'Risposta inattesa da teaerp.'];
    }

    /** @return array{ok:bool, status:int, data:array, message:?string} */
    private function request(string $method, string $path, array $body = []): array
    {
        if (! config('orio.tea.url') || ! config('orio.tea.user') || ! config('orio.tea.password')) {
            return ['ok' => false, 'status' => 0, 'data' => [], 'message' => 'Collegamento a TEA non configurato: indirizzo, utente e password vanno in Configurazione.'];
        }

        try {
            $response = Http::timeout(config('orio.tea.timeout', 30))->acceptJson()
                ->withBasicAuth(config('orio.tea.user'), config('orio.tea.password'))
                ->{$method}(rtrim(config('orio.tea.url'), '/') . $path, $body ?: null);
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 0, 'data' => [], 'message' => 'teaerp non raggiungibile: ' . $e->getMessage()];
        }

        $data = $response->json() ?? [];
        if ($response->successful() && ($data['ok'] ?? false)) {
            return ['ok' => true, 'status' => $response->status(), 'data' => $data, 'message' => null];
        }

        $details = $data['error']['details'] ?? [];

        return [
            'ok' => false,
            'status' => $response->status(),
            'data' => $data,
            'message' => trim(($data['error']['message'] ?? $data['message'] ?? ('Errore HTTP ' . $response->status()))
                . ($details ? ' ' . implode('; ', $details) : '')),
        ];
    }
}
