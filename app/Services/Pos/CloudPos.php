<?php

namespace App\Services\Pos;

use App\Models\PosTransaction;
use App\Models\Sale;
use Illuminate\Support\Facades\Cache;

/**
 * POS con carta pilotato da Orio (MultiSafepay SmartPOS in "cloud mode"): Orio manda l'importo
 * al terminale, il cliente paga sul POS e Orio legge l'esito. Le implementazioni cambiano solo
 * il trasporto (send): simulato oppure l'API MultiSafepay.
 *
 * Stati MultiSafepay: initialized (in attesa), declined (carta rifiutata, il cliente puo' riprovare
 * sul terminale), completed (pagato), cancelled / void / expired (annullato o scaduto dopo 60 s).
 */
abstract class CloudPos
{
    public const FINAL_CANCELLED = ['cancelled', 'void', 'expired'];

    /** Terminale su cui lavorano le chiamate: {id: TID, name, api_key} */
    protected ?array $terminal = null;

    /** @return array{0:int, 1:array} codice HTTP e corpo JSON della risposta */
    abstract protected function send(string $method, string $path, array $body = []): array;

    /**
     * Terminali tra cui scegliere in cassa (SmartPOS, telefoni Tap to Pay): da POS_TERMINALS
     * ("Nome|TID|chiave gruppo", separati da virgola) oppure l'unico MULTISAFEPAY_TERMINAL_ID.
     * Ogni telefono Tap to Pay ha il suo gruppo terminali, quindi la sua chiave API.
     *
     * @return list<array{id:string, name:string, api_key:?string}>
     */
    public static function terminals(): array
    {
        $defaultKey = config('orio.pos.api_key');
        $terminals = collect(explode(',', (string) config('orio.pos.terminals')))
            ->map(fn ($entry) => array_map('trim', explode('|', $entry)))
            ->filter(fn ($parts) => ($parts[1] ?? '') !== '')
            ->map(fn ($parts) => ['id' => $parts[1], 'name' => $parts[0] !== '' ? $parts[0] : $parts[1], 'api_key' => ($parts[2] ?? '') ?: $defaultKey])
            ->unique('id')->values()->all();

        if (! $terminals && config('orio.pos.terminal_id')) {
            $terminals = [['id' => (string) config('orio.pos.terminal_id'), 'name' => 'POS', 'api_key' => $defaultKey]];
        }
        if (! $terminals && config('orio.pos.driver') === 'fake') {
            $terminals = [['id' => 'FAKEPOS1', 'name' => 'SmartPOS (prova)', 'api_key' => null], ['id' => 'FAKETAP1', 'name' => 'Telefono Tap to Pay (prova)', 'api_key' => null]];
        }

        return $terminals;
    }

    /** Elenco per la cassa, senza chiavi API. */
    public static function terminalChoices(): array
    {
        return array_map(fn ($t) => ['id' => $t['id'], 'name' => $t['name']], static::terminals());
    }

    /** Lavora sul terminale indicato (TID); null o sconosciuto = il primo configurato. */
    public function forTerminal(?string $terminalId): static
    {
        $terminals = static::terminals();
        $clone = clone $this;
        $clone->terminal = collect($terminals)->firstWhere('id', $terminalId) ?? ($terminals[0] ?? null);

        return $clone;
    }

    public function terminalId(): ?string
    {
        return ($this->terminal ?? $this->forTerminal(null)->terminal)['id'] ?? null;
    }

    protected function apiKey(): ?string
    {
        return ($this->terminal ?? $this->forTerminal(null)->terminal)['api_key'] ?? null;
    }

    /** Manda l'importo della vendita al terminale. Ritorna l'order_id da seguire. */
    public function start(Sale $sale): string
    {
        // Un order_id nuovo a ogni tentativo: dopo un annullo la stessa vendita si riprova
        $orderId = 'orio-' . $sale->id . '-' . now()->format('YmdHis');
        $body = [
            'type' => 'redirect',
            'order_id' => $orderId,
            'gateway' => '',
            'currency' => 'EUR',
            'amount' => $sale->total_cents,
            'description' => 'Orio vendita #' . $sale->id,
            'gateway_info' => ['terminal_id' => (string) $this->terminalId()],
        ];
        if ($url = config('orio.pos.notification_url')) {
            $body['payment_options'] = ['notification_url' => $url, 'notification_method' => 'POST'];
        }

        $this->call('POST', '/orders', $body, $sale->id, $orderId, 'start');

        return $orderId;
    }

    /** @return array{status:string, transaction_id:?string} */
    public function status(string $orderId, ?int $saleId = null): array
    {
        $data = $this->call('GET', '/orders/' . rawurlencode($orderId), [], $saleId, $orderId, 'status', log: false);
        $status = strtolower((string) ($data['status'] ?? 'initialized'));

        // Nel registro solo i cambi di stato, non ogni lettura da due secondi
        if ($status !== 'initialized' && Cache::get("pos_status:{$orderId}") !== $status) {
            Cache::put("pos_status:{$orderId}", $status, now()->addDay());
            $this->log($saleId, $orderId, 'status', null, $data);
        }

        return ['status' => $status, 'transaction_id' => isset($data['transaction_id']) ? (string) $data['transaction_id'] : null];
    }

    public function cancel(string $orderId, ?int $saleId = null): string
    {
        $data = $this->call('POST', '/orders/' . rawurlencode($orderId) . '/cancel', [], $saleId, $orderId, 'cancel');

        return strtolower((string) ($data['status'] ?? ''));
    }

    /** Rimborso (totale) sulla carta usata: non serve ripassarla sul terminale. */
    public function refund(string $orderId, int $amountCents, ?int $saleId = null): void
    {
        $this->call('POST', '/orders/' . rawurlencode($orderId) . '/refunds', [
            'currency' => 'EUR',
            'amount' => $amountCents,
            'description' => 'Annullo Orio vendita #' . $saleId,
        ], $saleId, $orderId, 'refund');
    }

    private function call(string $method, string $path, array $body, ?int $saleId, string $orderId, string $action, bool $log = true): array
    {
        try {
            [$code, $json] = $this->send($method, $path, $body);
        } catch (\Throwable $e) {
            $this->log($saleId, $orderId, $action, $body, null, $e->getMessage());
            throw new PosException('POS non raggiungibile: ' . $e->getMessage(), 0, $e);
        }

        if ($code >= 400 || ! ($json['success'] ?? false)) {
            $message = $json['error_info'] ?? $json['error'] ?? ('HTTP ' . $code);
            $this->log($saleId, $orderId, $action, $body, $json, (string) $message);
            throw new PosException('Il POS ha rifiutato la richiesta: ' . $message);
        }

        if ($log) {
            $this->log($saleId, $orderId, $action, $body, $json);
        }

        return $json['data'] ?? [];
    }

    private function log(?int $saleId, string $orderId, string $action, ?array $request, ?array $response, ?string $error = null): void
    {
        PosTransaction::create([
            'sale_id' => $saleId,
            'terminal_id' => $this->terminalId(),
            'order_id' => $orderId,
            'action' => $action,
            'request' => $request ?: null,
            'response' => $response,
            'error' => $error ? mb_substr($error, 0, 255) : null,
        ]);
    }
}
