<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Rapporto giornaliero per il telefono del titolare (PWA da aggiungere alla schermata Home):
 * incassi del giorno divisi tra contanti (cassa automatica) e carta (POS) e venduto per operatore.
 */
class DailyReportController extends Controller
{
    public function show(Request $request)
    {
        $day = $this->day($request->query('giorno'));

        $sales = Sale::with(['payments', 'items', 'cashier:id,name', 'user:id,name'])
            ->whereIn('status', ['pagata', 'scontrinata', 'fatturata'])
            ->whereBetween('paid_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->get();

        // Annullate con documento di annullo: soldi restituiti, fuori dai totali
        [$voided, $valid] = $sales->partition(fn (Sale $sale) => $sale->receipt_voided_at !== null);

        $operators = $valid->groupBy(fn (Sale $sale) => $sale->cashierName() ?: '—')
            ->map(function ($group, $name) {
                $byType = $group->map(fn (Sale $sale) => $sale->paidByType());

                return [
                    'name' => $name,
                    'count' => $group->count(),
                    'total' => (int) $group->sum('paid_cents'),
                    'contanti' => (int) $byType->sum('contanti'),
                    'pos' => (int) $byType->sum('pos'),
                ];
            })
            ->sortByDesc('total')->values();

        $byType = $valid->map(fn (Sale $sale) => $sale->paidByType());
        $discounted = $valid->filter(fn (Sale $sale) => $sale->discount_percent);

        return view('report.daily', [
            'day' => $day,
            'prev' => $day->copy()->subDay(),
            'next' => $day->isToday() ? null : $day->copy()->addDay(),
            'total' => (int) $valid->sum('paid_cents'),
            'contanti' => (int) $byType->sum('contanti'),
            'pos' => (int) $byType->sum('pos'),
            'count' => $valid->count(),
            'scontrini' => $valid->where('status', 'scontrinata')->count(),
            'fatture' => $valid->where('status', 'fatturata')->count(),
            'daDocumentare' => $valid->where('status', 'pagata')->count(),
            'operators' => $operators,
            'discountCount' => $discounted->count(),
            'discountTotal' => (int) $discounted->sum(fn (Sale $sale) => $sale->discountCents()),
            'voidedCount' => $voided->count(),
            'voidedTotal' => (int) $voided->sum('paid_cents'),
        ]);
    }

    public function manifest()
    {
        return response()->json([
            'name' => 'Rapporto Orio',
            'short_name' => 'Rapporto Orio',
            'start_url' => route('report.daily', absolute: false),
            // Tutto il sito nello scope: il login deve restare dentro l'app, altrimenti si apre in Safari e il cookie non arriva all'app
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#f5f5f4',
            'theme_color' => '#1f7169',
            'icons' => collect([192, 512])->map(fn ($size) => [
                'src' => route('report.icon', $size, absolute: false), 'sizes' => "{$size}x{$size}", 'type' => 'image/png',
            ])->all(),
        ], 200, ['Content-Type' => 'application/manifest+json']);
    }

    public function icon(string $size)
    {
        return response()->file(resource_path("pwa/icon-{$size}.png"), ['Cache-Control' => 'private, max-age=86400']);
    }

    private function day(?string $value): Carbon
    {
        try {
            $day = $value ? Carbon::createFromFormat('Y-m-d', $value)->startOfDay() : today();
        } catch (\Throwable) {
            $day = today();
        }

        return $day->isFuture() ? today() : $day;
    }
}
