<?php

namespace App\Services;

use App\Models\Sale;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use SimpleXMLElement;

/**
 * Fatture su Fattura24 con la chiave di Le Essenze di Elda e il sezionale dedicato a orio
 * (IdNumerator), cosi' la numerazione non si mescola con quella principale.
 */
class Fattura24Service
{
    public function isConfigured(): bool
    {
        return (bool) config('orio.fattura24.api_key');
    }

    public function buildInvoiceXml(Sale $sale): string
    {
        $sale->loadMissing('items', 'customer');
        $customer = $sale->customer;

        $xml = new SimpleXMLElement('<Fattura24></Fattura24>');
        $document = $xml->addChild('Document');
        $document->addChild('DocumentType', 'FE');
        if ($numerator = config('orio.fattura24.id_numerator')) {
            $document->addChild('IdNumerator', $numerator);
        }

        $document->addChild('CustomerName', $this->text($customer->name));
        $document->addChild('CustomerAddress', $this->text($customer->address));
        $document->addChild('CustomerPostcode', $this->text($customer->postcode));
        $document->addChild('CustomerCity', $this->text($customer->city));
        $document->addChild('CustomerProvince', $this->text($customer->province));
        $document->addChild('CustomerCountry', $this->text($customer->country));
        $document->addChild('CustomerFiscalCode', $this->text($customer->fiscal_code));
        $document->addChild('CustomerVatCode', $this->text($customer->vat_code));
        $document->addChild('CustomerEmail', $this->text($customer->email));
        $document->addChild('FeCustomerPec', $this->text($customer->pec));
        $document->addChild('FeDestinationCode', $this->text($customer->sdi_code ?: '0000000'));

        // Totali dal lordo incassato (come emmycasa): imponibile scorporato per aliquota
        $withoutTax = 0.0;
        foreach ($sale->items as $item) {
            $withoutTax += $item->lineTotalCents() / 100 / (1 + $item->vat_rate / 100);
        }
        $gross = $sale->total_cents / 100;

        $document->addChild('Object', 'Vendita orio #' . $sale->id);
        $document->addChild('TotalWithoutTax', $this->money($withoutTax));
        $document->addChild('VatAmount', $this->money($gross - $withoutTax, 4));
        $document->addChild('Total', $this->money($gross, 4));
        $document->addChild('PaymentMethodName', $sale->payment_method === 'contanti' ? 'Contanti' : 'Carta');
        $document->addChild('PaymentMethodDescription', Sale::PAYMENT_METHODS[$sale->payment_method] ?? '');
        $document->addChild('SendEmail', 'false');

        $payments = $document->addChild('Payments');
        $payment = $payments->addChild('Payment');
        $payment->addChild('Date', now()->format('Y-m-d'));
        $payment->addChild('Amount', $this->money($gross));
        $payment->addChild('Paid', 'true');

        $rows = $document->addChild('Rows');
        foreach ($sale->items as $item) {
            $row = $rows->addChild('Row');
            $row->addChild('Description', $this->text($item->description));
            $row->addChild('Qty', $this->money($item->qty, 3));
            $row->addChild('Price', $this->money($item->unit_price_cents / 100 / (1 + $item->vat_rate / 100), 4));
            $row->addChild('VatCode', (string) $item->vat_rate);
            $row->addChild('VatDescription', $item->vat_rate . '%');
        }

        $dom = dom_import_simplexml($xml)->ownerDocument;
        $dom->formatOutput = true;

        return $dom->saveXML();
    }

    /**
     * @return array{success: bool, message: string, docId?: string, docNumber?: string}
     */
    public function saveDocument(string $xml, string $logName): array
    {
        Storage::put('fattura24_xml/' . $logName . '_' . now()->format('Ymd_His') . '.xml', $xml);

        if (! $this->isConfigured()) {
            return ['success' => false, 'message' => 'Chiave Fattura24 non configurata: XML generato ma non inviato.'];
        }

        try {
            $response = Http::asForm()->timeout(30)->post(config('orio.fattura24.base_uri') . 'SaveDocument', [
                'apiKey' => config('orio.fattura24.api_key'),
                'xml' => $xml,
            ]);
            $result = simplexml_load_string($response->body());
        } catch (\Throwable $e) {
            Log::error('Fattura24 SaveDocument: ' . $e->getMessage());

            return ['success' => false, 'message' => 'Errore di comunicazione con Fattura24: ' . $e->getMessage()];
        }

        if ($result === false) {
            return ['success' => false, 'message' => 'Risposta non valida da Fattura24.'];
        }

        if ((int) $result->returnCode !== 0) {
            return ['success' => false, 'message' => 'Fattura24: ' . $result->description];
        }

        return [
            'success' => true,
            'message' => (string) $result->description,
            'docId' => (string) $result->docId,
            'docNumber' => (string) $result->docNumber,
        ];
    }

    public function getPdf(string $docId): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $response = Http::asForm()->timeout(30)->post(config('orio.fattura24.base_uri') . 'GetFile', [
            'apiKey' => config('orio.fattura24.api_key'),
            'docId' => $docId,
        ]);

        return str_contains($response->header('Content-Type'), 'pdf') ? $response->body() : null;
    }

    private function text(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES);
    }

    private function money(float $value, int $decimals = 2): string
    {
        return number_format($value, $decimals, '.', '');
    }
}
