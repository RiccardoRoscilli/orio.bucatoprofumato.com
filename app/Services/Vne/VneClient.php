<?php

namespace App\Services\Vne;

use App\Models\VneTransaction;

/**
 * Comandi del protocollo VNE Automatic Cash 3.05 usati da orio. Le implementazioni
 * cambiano solo il trasporto (send): simulato, diretto dal server o, in produzione, via ponte.
 */
abstract class VneClient
{
    abstract protected function send(array $payload): array;

    /** Richiesta pagamento scontrino (tipo 1). Ritorna l'id transazione VNE. */
    public function pay(int $amountCents, bool $creditCard = false, ?int $saleId = null): string
    {
        $response = $this->call([
            'tipo' => 1,
            'importo' => $amountCents,
            'opName' => config('orio.vne.op_name'),
            'refundable' => 1,
            'credit_card' => $creditCard ? 1 : 0,
        ], $saleId);

        return (string) $response['id'];
    }

    /** Polling esito pagamento (tipo 2). */
    public function poll(string $id, ?int $saleId = null): array
    {
        $payload = ['tipo' => 2, 'id' => $id, 'opName' => config('orio.vne.op_name')];
        $response = $this->call($payload, $saleId, log: false);

        // Nel log va solo l'esito finale, non ogni polling da un secondo
        if ((int) ($response['payment_status'] ?? 0) === 1) {
            $this->log($payload, $response, $saleId);
        }

        return $response;
    }

    /**
     * Annullamento scontrino pendente (tipo 3).
     * $mode: 1 = accetta pagamento parziale, 2 = restituisci il denaro al cliente.
     */
    public function cancel(string $id, int $mode, ?int $saleId = null): array
    {
        return $this->call(['tipo' => 3, 'id' => $id, 'tipo_annullamento' => $mode, 'opName' => config('orio.vne.op_name')], $saleId);
    }

    /** Lista scontrini pendenti (tipo 5). */
    public function pending(): array
    {
        return $this->call(['tipo' => 5, 'opName' => config('orio.vne.op_name')], log: false);
    }

    /** Stato della macchina (tipo 20). */
    public function status(): array
    {
        return $this->call(['tipo' => 20, 'opName' => config('orio.vne.op_name')], log: false);
    }

    /** Chiusura cassa (tipo 60). */
    public function closure(): array
    {
        return $this->call(['tipo' => 60, 'opName' => config('orio.vne.op_name')]);
    }

    protected function call(array $payload, ?int $saleId = null, bool $log = true): array
    {
        try {
            $response = $this->send($payload);
        } catch (VneException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->log($payload, null, $saleId, $e->getMessage());
            throw new VneException('Cassa VNE non raggiungibile: ' . $e->getMessage(), 0, $e);
        }

        if ((int) ($response['req_status'] ?? 1) === 2) {
            $this->log($payload, $response, $saleId, 'NACK ' . ($response['mess'] ?? ''));
            throw VneException::fromNack($response['mess'] ?? null);
        }

        if ($log) {
            $this->log($payload, $response, $saleId);
        }

        return $response;
    }

    protected function log(array $payload, ?array $response, ?int $saleId, ?string $error = null): void
    {
        VneTransaction::create([
            'sale_id' => $saleId,
            'vne_id' => $response['id'] ?? $payload['id'] ?? null,
            'tipo' => $payload['tipo'],
            'request' => $payload,
            'response' => $response,
            'error' => $error,
        ]);
    }
}
