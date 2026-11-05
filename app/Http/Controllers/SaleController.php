<?php

namespace App\Http\Controllers;

use App\Services\Closure\ClosureStatus;
use App\Models\Sale;
use App\Services\FiscalPrinter\EpsonFpMateXml;
use App\Services\Vne\FakeVneClient;
use Illuminate\Http\Request;

class SaleController extends Controller
{
    public function cassa()
    {
        return view('cassa', [
            'vneDriver' => config('orio.vne.driver'),
            'scenarios' => FakeVneClient::SCENARIOS,
        ]);
    }

    public function index(Request $request)
    {
        $day = $request->date('day') ?? today();

        $sales = Sale::with('customer', 'user')
            ->whereDate('created_at', $day)
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->latest()
            ->get();

        return view('sales.index', ['sales' => $sales, 'day' => $day, 'status' => $request->status]);
    }

    public function show(Sale $sale)
    {
        $sale->load('items', 'customer', 'user', 'vneTransactions');

        return view('sales.show', [
            'sale' => $sale,
            'receiptXml' => $sale->receipt_xml ?? ($sale->items->isNotEmpty() ? EpsonFpMateXml::receipt($sale) : null),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'document_type' => 'required|in:scontrino,fattura',
            'customer_id' => 'nullable|required_if:document_type,fattura|exists:customers,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.sku' => 'nullable|string|max:255',
            'items.*.description' => 'required|string|max:255',
            'items.*.qty' => 'required|numeric|min:0.001',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.vat_rate' => 'required|in:22,10,5,4',
        ], [
            'customer_id.required_if' => 'Per la fattura serve il cliente: cerca la partita IVA.',
            'items.required' => 'Aggiungi almeno una riga.',
            'items.*.product_id.required' => 'Si possono vendere solo prodotti del catalogo bucatoprofumato.com.',
        ]);

        if ($data['document_type'] === 'scontrino' && $block = app(ClosureStatus::class)->receiptBlockMessage()) {
            return response()->json(['message' => $block], 423);
        }

        $sale = Sale::create([
            'user_id' => $request->user()->id,
            'customer_id' => $data['document_type'] === 'fattura' ? $data['customer_id'] : null,
            'document_type' => $data['document_type'],
        ]);

        foreach ($data['items'] as $item) {
            $sale->items()->create([
                'product_id' => $item['product_id'] ?? null,
                'sku' => $item['sku'] ?? null,
                'description' => $item['description'],
                'qty' => $item['qty'],
                'unit_price_cents' => (int) round($item['price'] * 100),
                'vat_rate' => $item['vat_rate'],
            ]);
        }

        $sale->load('items');
        $sale->recalculateTotal();
        $sale->save();

        if ($sale->total_cents <= 0) {
            $sale->delete();

            return response()->json(['message' => 'Il totale deve essere maggiore di zero.'], 422);
        }

        return response()->json(['sale' => $sale]);
    }

    public function cancel(Sale $sale)
    {
        abort_if(in_array($sale->status, ['scontrinata', 'fatturata', 'in_pagamento'], true), 422, 'Vendita non annullabile in questo stato.');

        $sale->update(['status' => 'annullata', 'closed_at' => now()]);

        return redirect()->route('sales.show', $sale)->with('status', 'Vendita annullata.');
    }
}
