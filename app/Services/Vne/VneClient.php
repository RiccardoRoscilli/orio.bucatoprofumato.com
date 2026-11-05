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
            'opName' => $this->opName(),
            'refundable' => 1,
            'credit_card' => $creditCard ? 1 : 0,
        ], $saleId);

        return (string) $response['id'];
    }

    /** Polling esito pagamento (tipo 2). */
    public function poll(string $id, ?int $saleId = null): array
    {
        $payload = ['tipo' => 2, 'id' => $id, 'opName' => $this->opName()];
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
        $payload = ['tipo' => 3, 'id' => $id, 'tipo_annullamento' => $mode, 'opName' => $this->opName()];

        try {
            return $this->call($payload, $saleId);
        } catch (VneException $e) {
            // Su alcune installazioni VNE annulla solo l'operatore che ha avviato il pagamento
            // (NACK 114): si riprova con opName vuoto, che la macchina tratta come super user.
            if ($e->getCode() !== 114) {
                throw $e;
            }

            return $this->call(['opName' => ''] + $payload, $saleId);
        }
    }

    /** Lista scontrini pendenti (tipo 5). */
    public function pending(): array
    {
        return $this->call(['tipo' => 5, 'opName' => $this->opName()], log: false);
    }

    /** Stato della macchina (tipo 20). */
    public function status(): array
    {
        return $this->call(['tipo' => 20, 'opName' => $this->opName()], log: false);
    }

    /** Chiusura cassa (tipo 60). */
    public function closure(): array
    {
        return $this->call(['tipo' => 60, 'opName' => $this->opName()]);
    }

    /**
     * Operatore comunicato a VNE: lo username dell'utente orio collegato, cosi' nei report e
     * nelle chiusure della cassa automatica si vede chi ha incassato. Non serve che esista
     * sulla macchina (protocollo 3.05, par. 14). Senza utente (es. comandi da console) si usa
     * il nome di ripiego VNE_OP_NAME.
     */
    protected function opName(): string
    {
        return auth()->user()?->username ?? config('orio.vne.op_name');
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
