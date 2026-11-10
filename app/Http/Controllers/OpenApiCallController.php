<?php

namespace App\Http\Controllers;

use App\Models\OpenApiCall;
use Illuminate\Http\Request;

/**
 * Riepilogo mensile delle chiamate OpenAPI fatte da Orio, per rifatturarle:
 * l'account OpenAPI e' condiviso con emmycasa, qui si vedono solo quelle di Orio.
 */
class OpenApiCallController extends Controller
{
    public function index(Request $request)
    {
        $month = $this->month($request);
        $priceCents = (int) config('orio.openapi.price_cents');

        $calls = OpenApiCall::with('user')->inMonth($month)->latest('created_at')->get();
        $charged = $calls->where('charged', true)->count();

        // Ultimi 12 mesi, raggruppati in PHP (in locale sqlite, in produzione MySQL)
        $months = OpenApiCall::where('charged', true)
            ->where('created_at', '>=', now()->subMonths(11)->startOfMonth())
            ->pluck('created_at')
            ->countBy(fn ($date) => $date->format('Y-m'))
            ->sortKeysDesc();

        return view('openapi-calls', [
            'month' => $month,
            'calls' => $calls,
            'charged' => $charged,
            'priceCents' => $priceCents,
            'totalCents' => $charged * $priceCents,
            'months' => $months,
        ]);
    }

    public function csv(Request $request)
    {
        $month = $this->month($request);
        $calls = OpenApiCall::with('user')->inMonth($month)->oldest('created_at')->get();

        return response()->streamDownload(function () use ($calls) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM: Excel apre correttamente gli accenti
            fputcsv($out, ['Data', 'Utente', 'Servizio', 'Partita IVA', 'Esito HTTP', 'Ambiente', 'Addebitata'], ';');
            foreach ($calls as $call) {
                fputcsv($out, [
                    $call->created_at->format('d/m/Y H:i'),
                    $call->user?->name ?? '',
                    $call->service,
                    $call->vat_code,
                    $call->http_status ?? 'errore rete',
                    $call->live ? 'produzione' : 'sandbox',
                    $call->charged ? 'si' : 'no',
                ], ';');
            }
            fclose($out);
        }, "openapi-orio-{$month}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function month(Request $request): string
    {
        $month = (string) $request->query('month', now()->format('Y-m'));

        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) ? $month : now()->format('Y-m');
    }
}
