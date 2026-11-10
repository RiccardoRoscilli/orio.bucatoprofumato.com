<?php

namespace App\Services;

use App\Models\OpenApiCall;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Dati societa' da partita IVA (company.openapi.com), stesso account di emmycasa.
 * Ogni chiamata finisce in openapi_calls per poterla rifatturare (pagina /openapi).
 */
class OpenApiService
{
    private const SERVICE = 'IT-start';

    public function isConfigured(): bool
    {
        return config('orio.openapi.email') && config('orio.openapi.password');
    }

    /**
     * @return array<string, string>|null dati normalizzati per l'anagrafica clienti
     */
    public function companyByVat(string $vatCode): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $live = config('orio.openapi.live');
        $url = ($live ? 'https://company.openapi.com/IT-start/' : 'https://test.company.openapi.com/IT-start/') . urlencode($vatCode);

        try {
            $response = Http::withToken($this->token())->timeout(30)->get($url);

            // Token scaduto o revocato: rigenera e riprova una volta
            if ($response->status() === 401) {
                Cache::forget($this->cacheKey());
                $response = Http::withToken($this->token())->timeout(30)->get($url);
            }
        } catch (ConnectionException $e) {
            OpenApiCall::record(self::SERVICE, $vatCode, null, $live);

            throw $e;
        }

        // Registro per la rifatturazione: l'account e' condiviso con emmycasa
        OpenApiCall::record(self::SERVICE, $vatCode, $response->status(), $live);

        if (! $response->successful() || ! isset($response->json('data')[0])) {
            Log::warning('OpenAPI P.IVA ' . $vatCode . ': HTTP ' . $response->status());

            return null;
        }

        $company = $response->json('data')[0];
        $office = $company['address']['registeredOffice'] ?? [];

        return [
            'name' => $company['companyName'] ?? '',
            'vat_code' => $company['vatCode'] ?? $vatCode,
            'fiscal_code' => $company['taxCode'] ?? '',
            'address' => $office['streetName'] ?? '',
            'postcode' => $office['zipCode'] ?? '',
            'city' => $office['town'] ?? '',
            'province' => $office['province'] ?? '',
            // Nessun codice destinatario registrato: 0000000, la fattura va nel cassetto fiscale
            'sdi_code' => ($company['sdiCode'] ?? '') ?: '0000000',
        ];
    }

    private function token(): string
    {
        return Cache::remember($this->cacheKey(), now()->addDays(29), function () {
            $live = config('orio.openapi.live');
            $response = Http::withBasicAuth(config('orio.openapi.email'), config('orio.openapi.password'))
                ->asJson()
                ->post($live ? 'https://oauth.openapi.it/token' : 'https://test.oauth.openapi.it/token', [
                    // Stessi scope di emmycasa
                    'scopes' => ['GET:company.openapi.com/*', '*:*/*'],
                    'ttl' => 2592000,
                ]);

            if (! $response->successful() || ! $response->json('token')) {
                throw new \RuntimeException('Impossibile ottenere il token OpenAPI (HTTP ' . $response->status() . ')');
            }

            return $response->json('token');
        });
    }

    private function cacheKey(): string
    {
        return config('orio.openapi.live') ? 'openapi_live_token' : 'openapi_sandbox_token';
    }
}
