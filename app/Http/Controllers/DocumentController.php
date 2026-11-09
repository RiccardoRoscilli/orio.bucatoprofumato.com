<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Services\Closure\ClosureStatus;
use App\Services\Fattura24Service;
use App\Services\FiscalPrinter\AxonG100;
use App\Services\FiscalPrinter\EpsonFpMateXml;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    /**
     * Emissione scontrino. Con la stampante simulata il numero viene assegnato qui; con la
     * Axon DADO (o la Epson) i comandi tornano al browser della postazione, che li manda alla
     * stampante in rete locale e poi conferma il numero con receiptConfirm().
     */
    public function receipt(Sale $sale)
    {
        abort_unless($sale->status === 'pagata', 422, 'La vendita non è pagata.');

        if ($block = app(ClosureStatus::class)->receiptBlockMessage()) {
            return response()->json(['message' => $block], 423);
        }

        $sale->load('items');

        if (config('orio.fiscal_printer.driver') === 'axon') {
            $commands = AxonG100::receipt($sale);
            $sale->update(['receipt_xml' => implode("\n", $commands)]);

            return response()->json([
                'driver' => 'axon',
                'base' => 'http://' . config('orio.fiscal_printer.host'),
                'commands' => $commands,
                'abort' => AxonG100::abortOpenDocument(),
            ]);
        }

        $xml = EpsonFpMateXml::receipt($sale);
        $sale->update(['receipt_xml' => $xml]);

        if (config('orio.fiscal_printer.driver') === 'epson') {
            return response()->json([
                'driver' => 'epson',
                'url' => 'https://' . config('orio.fiscal_printer.host') . '/cgi-bin/fpmate.cgi',
                'xml' => $xml,
            ]);
        }

        $number = sprintf('%04d-%04d', now()->dayOfYear, Sale::whereDate('closed_at', today())->where('status', 'scontrinata')->count() + 1);
        $sale->update(['status' => 'scontrinata', 'receipt_number' => $number, 'closed_at' => now()]);

        return response()->json(['driver' => 'fake', 'sale' => $sale, 'preview_url' => route('sales.receipt', $sale)]);
    }

    public function receiptConfirm(Request $request, Sale $sale)
    {
        $data = $request->validate(['receipt_number' => 'required|string|max:30']);

        $sale->update(['status' => 'scontrinata', 'receipt_number' => $data['receipt_number'], 'closed_at' => now()]);

        return response()->json(['sale' => $sale]);
    }

    public function receiptPreview(Sale $sale)
    {
        $sale->load('items', 'vneTransactions');

        $final = $sale->vneTransactions
            ->where('tipo', 2)
            ->map(fn ($t) => $t->response['payment_details'] ?? null)
            ->filter(fn ($d) => ($d['status'] ?? null) === 'completed')
            ->last();

        return view('sales.receipt', ['sale' => $sale, 'vne' => $final]);
    }

    public function invoice(Sale $sale, Fattura24Service $fattura24)
    {
        abort_unless($sale->status === 'pagata' && $sale->customer_id, 422, 'La vendita non è pagata o manca il cliente.');

        $xml = $fattura24->buildInvoiceXml($sale);
        $sale->update(['fattura24_xml' => $xml]);

        $result = $fattura24->saveDocument($xml, 'vendita_' . $sale->id);

        if ($result['success']) {
            $sale->update([
                'status' => 'fatturata',
                'fattura24_doc_id' => $result['docId'],
                'fattura24_doc_number' => $result['docNumber'],
                'closed_at' => now(),
            ]);
        }

        return response()->json($result + ['sale' => $sale, 'show_url' => route('sales.show', $sale)], $result['success'] ? 200 : 202);
    }

    public function invoicePdf(Sale $sale, Fattura24Service $fattura24)
    {
        $pdf = $sale->fattura24_doc_id ? $fattura24->getPdf($sale->fattura24_doc_id) : null;

        abort_unless($pdf, 404, 'PDF non disponibile.');

        return response($pdf, 200, ['Content-Type' => 'application/pdf']);
    }
}
