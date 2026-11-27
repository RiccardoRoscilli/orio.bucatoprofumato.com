<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\TeaDdt;
use App\Models\TeaDdtItem;
use App\Models\TeaOrder;
use App\Models\TeaOrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Riceve da teaerp i cesti natalizi: TEA li fattura e li spedisce di propria iniziativa, Orio
 * non fa mai un vero ordine. Senza questa rotta non esisterebbe nessun ordine locale da
 * spuntare quando arriva la merce, quindi la creiamo qui come se Orio l'avesse già inviata e
 * TEA l'avesse già evasa (stesso risultato di TeaOrderController::store()+deliver(), ma in un
 * solo passaggio): compare in "/ordini-tea" pronta per la spunta con lo stesso meccanismo
 * degli ordini normali.
 *
 * Unica rotta nel verso opposto rispetto al resto dell'integrazione (qui Orio è sempre il
 * chiamante, vedi TeaOrderClient): niente utente teaerp da autenticare con le credenziali
 * normali, solo un segreto condiviso fra i due server.
 */
class TeaCestiOrderController extends Controller
{
    public function store(Request $request)
    {
        $secret = config('services.tea_cesti.secret');
        if (! $secret || ! hash_equals($secret, (string) $request->header('X-Tea-Secret'))) {
            return response()->json(['ok' => false, 'message' => 'Non autorizzato.'], 403);
        }

        $data = $request->validate([
            'reference' => 'required|string|max:50',
            'tea_increment_id' => 'nullable|string|max:30',
            'ddt_number' => 'required|string|max:60',
            'issued_at' => 'nullable|date',
            'items' => 'required|array|min:1',
            'items.*.sku' => 'required|string|max:64',
            'items.*.name' => 'required|string|max:255',
            'items.*.qty' => 'required|integer|min:1',
            // Prezzo unitario fatturato da TEA (listino 1), IVA inclusa: totale dell'ordine.
            'items.*.unit_price_cents' => 'required|integer|min:0',
            // Prezzo al pubblico IVA inclusa: prezzo di vendita in cassa del cesto quando arriva
            // per la prima volta. Se manca (teaerp vecchio) si usa quello fatturato.
            'items.*.retail_price_cents' => 'nullable|integer|min:0',
            'items.*.vat_rate' => 'nullable|integer|min:0|max:100',
            // Contenuto del cesto (ricetta teaerp): per trovarlo in cassa cercando un prodotto dentro.
            'items.*.components' => 'nullable|array',
            'items.*.components.*.sku' => 'required|string|max:64',
            'items.*.components.*.name' => 'required|string|max:255',
            'items.*.components.*.qty' => 'required|numeric|min:0',
        ]);

        // Stesso riferimento già visto: non duplicare (idempotente, come teaerp fa con noi).
        if ($existing = TeaOrder::where('reference', $data['reference'])->first()) {
            return response()->json(['ok' => true, 'created' => false, 'order_id' => $existing->id]);
        }

        $order = DB::transaction(function () use ($data) {
            $subtotalCents = 0;
            $taxCents = 0;
            $lines = [];

            foreach ($data['items'] as $item) {
                $vatRate = $item['vat_rate'] ?? 22;
                $grossRowCents = $item['unit_price_cents'] * $item['qty'];
                $netRowCents = (int) round($grossRowCents / (1 + $vatRate / 100));
                $subtotalCents += $netRowCents;
                $taxCents += $grossRowCents - $netRowCents;

                // Il cesto è quasi sempre un prodotto nuovo: lo creiamo al volo alla prima
                // spedizione, già vendibile in cassa (sold_in_shop) perché è letteralmente lo
                // scopo. Se esiste già (una seconda tiratura dello stesso cesto) non ne
                // tocchiamo il prezzo, che da quel momento è gestito in Orio come gli altri.
                $product = Product::firstOrCreate(
                    ['sku' => $item['sku']],
                    [
                        'name' => $item['name'],
                        'price_cents' => $item['retail_price_cents'] ?? $item['unit_price_cents'],
                        'vat_rate' => $vatRate,
                        'active' => true,
                        'sold_in_shop' => true,
                        // Non è sul sito: senza shop_only il sync notturno del catalogo lo disattiva
                        'shop_only' => true,
                        'pcs_per_box' => 1,
                        'in_stock' => true,
                    ]
                );

                // Cesti arrivati prima di shop_only: il sync li aveva disattivati
                if (! $product->shop_only && ! $product->synced_at) {
                    $product->update(['shop_only' => true, 'active' => true]);
                }

                // Il contenuto invece lo aggiorniamo sempre: è l'ultima ricetta nota del cesto.
                if (isset($item['components'])) {
                    $product->components()->delete();
                    $product->components()->createMany(collect($item['components'])
                        ->map(fn ($c) => ['sku' => $c['sku'], 'name' => $c['name'], 'qty' => $c['qty']])->all());
                }

                $lines[] = $item + ['product' => $product, 'net_row_cents' => $netRowCents];
            }

            $order = TeaOrder::create([
                'reference' => $data['reference'],
                'status' => 'inviato',
                'tea_increment_id' => $data['tea_increment_id'] ?? null,
                'tea_status' => 'Completo',
                'subtotal_cents' => $subtotalCents,
                'tax_cents' => $taxCents,
                'total_cents' => $subtotalCents + $taxCents,
                'notes' => 'Cesto natalizio: fatturato e spedito da TEA, nessun ordine fatto da Orio.',
                'sent_at' => now(),
                'status_checked_at' => now(),
            ]);

            foreach ($lines as $line) {
                TeaOrderItem::create([
                    'tea_order_id' => $order->id,
                    'product_id' => $line['product']->id,
                    'sku' => $line['sku'],
                    'name' => $line['name'],
                    'qty' => $line['qty'],
                    'price_cents' => (int) round($line['net_row_cents'] / $line['qty']),
                    'row_total_cents' => $line['net_row_cents'],
                ]);
            }

            $ddt = TeaDdt::create([
                'tea_order_id' => $order->id,
                'ddt_number' => $data['ddt_number'],
                'issued_at' => $data['issued_at'] ?? now(),
            ]);

            foreach ($lines as $line) {
                TeaDdtItem::create([
                    'tea_ddt_id' => $ddt->id,
                    'product_id' => $line['product']->id,
                    'sku' => $line['sku'],
                    'name' => $line['name'],
                    'pcs_per_box' => 1,
                    'qty_shipped' => $line['qty'],
                ]);
            }

            return $order;
        });

        return response()->json(['ok' => true, 'created' => true, 'order_id' => $order->id], 201);
    }
}
