<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\OpenApiService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    /**
     * Cerca prima in anagrafica e chiama OpenAPI solo se la partita IVA non c'e': ogni
     * chiamata e' a pagamento, quindi i dati ottenuti vengono salvati subito in anagrafica
     * e la ricerca successiva della stessa partita IVA non richiama piu' l'API.
     */
    public function lookupVat(string $vat, OpenApiService $openApi)
    {
        $vat = preg_replace('/\D/', '', $vat);

        if (strlen($vat) !== 11) {
            return response()->json(['error' => 'La partita IVA deve avere 11 cifre.'], 422);
        }

        if ($customer = Customer::where('vat_code', $vat)->first()) {
            return response()->json(['source' => 'anagrafica', 'customer' => $customer]);
        }

        if (! $openApi->isConfigured()) {
            return response()->json(['error' => 'Credenziali OpenAPI non configurate: inserisci i dati a mano.', 'customer' => ['vat_code' => $vat]], 404);
        }

        try {
            $data = $openApi->companyByVat($vat);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage(), 'customer' => ['vat_code' => $vat]], 502);
        }

        if (! $data) {
            return response()->json(['error' => 'Nessuna società trovata per questa partita IVA.', 'customer' => ['vat_code' => $vat]], 404);
        }

        $customer = Customer::updateOrCreate(['vat_code' => $data['vat_code'] ?: $vat], $data);

        return response()->json(['source' => 'openapi', 'customer' => $customer]);
    }

    public function store(Request $request)
    {
        // Dalla finestra cliente: aggiorna il cliente indicato (o quello con la stessa P.IVA) oppure lo crea
        $existing = $request->filled('id')
            ? Customer::findOrFail($request->input('id'))
            : Customer::where('vat_code', $request->input('vat_code'))->first();
        $data = $this->validated($request, $existing);
        $customer = $existing ? tap($existing)->update($data) : Customer::create($data);

        return response()->json(['customer' => $customer]);
    }

    /** Ricerca clienti gia' in anagrafica per ragione sociale, P.IVA o citta' (cassa). */
    public function search(Request $request)
    {
        $terms = array_filter(preg_split('/\s+/', trim((string) $request->input('q'))));

        $customers = $terms ? Customer::where(function ($q) use ($terms) {
            foreach ($terms as $term) {
                $q->where(fn ($q) => $q->where('name', 'like', "%{$term}%")
                    ->orWhere('vat_code', 'like', "%{$term}%")
                    ->orWhere('city', 'like', "%{$term}%"));
            }
        })->orderBy('name')->limit(10)->get() : collect();

        return response()->json(['customers' => $customers]);
    }

    public function index()
    {
        return view('customers.index');
    }

    /** Sorgente server-side della DataTable clienti: ricerca a piu' parole come per i prodotti. */
    public function data(Request $request)
    {
        $columns = [0 => 'name', 1 => 'vat_code', 2 => 'city', 3 => 'sdi_code', 4 => 'sales_count'];
        $total = Customer::count();

        $terms = array_filter(preg_split('/\s+/', trim((string) $request->input('search.value'))));
        $filtered = Customer::query()->where(function ($q) use ($terms) {
            foreach ($terms as $term) {
                $q->where(fn ($q) => $q
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('vat_code', 'like', "%{$term}%")
                    ->orWhere('fiscal_code', 'like', "%{$term}%")
                    ->orWhere('city', 'like', "%{$term}%")
                    ->orWhere('province', 'like', "%{$term}%")
                    ->orWhere('sdi_code', 'like', "%{$term}%")
                    ->orWhere('pec', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%"));
            }
        });

        $column = $columns[(int) $request->input('order.0.column')] ?? 'name';
        $dir = $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc';

        $customers = (clone $filtered)
            ->withCount('sales')
            ->orderBy($column, $dir)
            ->offset((int) $request->input('start', 0))
            ->limit(min(200, max(1, (int) $request->input('length', 50))))
            ->get();

        return response()->json([
            'draw' => (int) $request->input('draw'),
            'recordsTotal' => $total,
            'recordsFiltered' => $terms ? $filtered->count() : $total,
            'data' => $customers->map(fn (Customer $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'vat_code' => $c->vat_code,
                'fiscal_code' => $c->fiscal_code,
                'city' => trim("{$c->postcode} {$c->city} " . ($c->province ? "({$c->province})" : '')),
                'sdi_code' => $c->sdi_code,
                'pec' => $c->pec,
                'sales_count' => $c->sales_count,
                'customer' => $c->only(['id', 'name', 'vat_code', 'fiscal_code', 'address', 'postcode', 'city', 'province', 'country', 'sdi_code', 'pec', 'email', 'phone']),
            ]),
        ]);
    }

    private function validated(Request $request, ?Customer $customer = null): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'vat_code' => ['required', 'digits:11', Rule::unique('customers', 'vat_code')->ignore($customer?->id)],
            'fiscal_code' => 'nullable|string|max:16',
            'address' => 'required|string|max:255',
            'postcode' => 'required|string|max:10',
            'city' => 'required|string|max:255',
            'province' => 'nullable|string|max:5',
            'sdi_code' => 'nullable|string|size:7',
            'pec' => 'nullable|email|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'country' => 'nullable|string|size:2',
        ], [
            'sdi_code.size' => 'Il codice SDI deve avere 7 caratteri.',
            'vat_code.unique' => 'Esiste già un cliente con questa partita IVA.',
        ]);

        $data['sdi_code'] = isset($data['sdi_code']) ? strtoupper($data['sdi_code']) : '0000000';
        $data['province'] = isset($data['province']) ? strtoupper($data['province']) : null;
        $data['country'] = strtoupper($data['country'] ?? 'IT');
        $data['fiscal_code'] = isset($data['fiscal_code']) ? strtoupper($data['fiscal_code']) : null;

        return $data;
    }
}
