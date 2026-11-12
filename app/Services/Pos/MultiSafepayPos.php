<?php

namespace App\Services\Pos;

use Illuminate\Support\Facades\Http;

/** API MultiSafepay con la chiave del gruppo terminali (non quella del sito web). */
class MultiSafepayPos extends CloudPos
{
    protected function send(string $method, string $path, array $body = []): array
    {
        $key = config('orio.pos.api_key');
        if (! $key || ! config('orio.pos.terminal_id')) {
            throw new PosException('POS MultiSafepay non configurato: mancano MULTISAFEPAY_POS_API_KEY o MULTISAFEPAY_TERMINAL_ID.');
        }

        $request = Http::timeout(config('orio.pos.timeout', 15))->acceptJson()
            ->withQueryParameters(['api_key' => $key]);
        $url = rtrim(config('orio.pos.base_url'), '/') . $path;
        $response = $method === 'GET' ? $request->get($url) : $request->post($url, $body ?: (object) []);

        return [$response->status(), $response->json() ?? []];
    }
}
