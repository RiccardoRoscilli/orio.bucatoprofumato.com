<?php

namespace App\Services\Pos;

use Illuminate\Support\Facades\Http;

/** API MultiSafepay con la chiave del gruppo terminali (non quella del sito web). */
class MultiSafepayPos extends CloudPos
{
    protected function send(string $method, string $path, array $body = []): array
    {
        $key = $this->apiKey();
        if (! $key || ! $this->terminalId()) {
            throw new PosException('POS MultiSafepay non configurato: mancano la chiave del gruppo terminali o il TID (MULTISAFEPAY_POS_API_KEY, MULTISAFEPAY_TERMINAL_ID o POS_TERMINALS).');
        }

        $request = Http::timeout(config('orio.pos.timeout', 15))->acceptJson()
            ->withQueryParameters(['api_key' => $key]);
        $url = rtrim(config('orio.pos.base_url'), '/') . $path;
        $response = $method === 'GET' ? $request->get($url) : $request->post($url, $body ?: (object) []);

        return [$response->status(), $response->json() ?? []];
    }
}
