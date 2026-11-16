<?php

namespace App\Services\Vne;

use Illuminate\Http\JsonResponse;

/**
 * La richiesta per la VNE va mandata dal browser del tablet (BrowserVneClient): risposta 428
 * con url e payload, la pagina la inoltra e ripete la chiamata con la risposta allegata.
 */
class VneRelayRequired extends \RuntimeException
{
    public function __construct(public readonly string $url, public readonly array $payload, public readonly int $index)
    {
        parent::__construct('La richiesta alla cassa automatica va inoltrata dal tablet.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => 'Questa pagina deve essere aperta dal tablet di cassa, sulla rete del negozio.',
            'vne_relay' => ['url' => $this->url, 'payload' => $this->payload, 'index' => $this->index],
        ], 428);
    }
}
