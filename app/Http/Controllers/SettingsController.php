<?php

namespace App\Http\Controllers;

use App\Services\Pos\CloudPos;
use App\Services\Tea\TeaOrderClient;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Configurazione > dati del negozio, stampante fiscale, cassetto contanti (VNE), terminali carta,
 * Fattura24, chiusura giornata e collegamento a TEA. I valori salvati vincono sul .env.
 */
class SettingsController extends Controller
{
    public const VAT_RATES = [22, 10, 5, 4];

    public const DRIVERS = [
        'fiscal_printer' => ['fake' => 'Simulata (non stampa)', 'axon' => 'Axon DADO RT (WyCash 600)', 'epson' => 'Epson FPMate'],
        'vne' => ['fake' => 'Simulato (prova)', 'direct' => 'Collegato'],
        'pos' => ['manual' => 'Non collegato: importo digitato sul POS', 'multisafepay' => 'Collegato: MultiSafepay (SmartPOS / Tap to Pay)', 'fake' => 'Simulato (prova)'],
    ];

    private const HOST = 'regex:/^[A-Za-z0-9.\-]+(:\d{1,5})?$/';

    public function index()
    {
        $c = config('orio');

        return view('settings', [
            'c' => $c,
            'drivers' => self::DRIVERS,
            'departments' => collect(self::VAT_RATES)->mapWithKeys(fn ($rate) => [$rate => $c['fiscal_printer']['departments'][$rate] ?? 1])->all(),
            'terminals' => array_map(fn ($t) => [
                'id' => $t['id'], 'name' => $t['name'], 'active' => $t['active'], 'original' => $t['id'],
                'key_hint' => self::hint($t['api_key']), 'api_key' => '',
            ], CloudPos::configuredTerminals()),
            'defaultKeyHint' => self::hint(config('orio.pos.api_key')),
            'secrets' => [
                'fattura24.api_key' => self::hint($c['fattura24']['api_key']),
                'tea.password' => self::hint($c['tea']['password']),
            ],
        ]);
    }

    public function update(Request $request)
    {
        $data = Validator::make($request->all(), [
            'company.name' => 'required|string|max:100',
            'company.address' => 'nullable|string|max:150',
            'company.vat_code' => 'nullable|string|max:20',

            'fiscal_printer.driver' => ['required', Rule::in(array_keys(self::DRIVERS['fiscal_printer']))],
            'fiscal_printer.host' => ['nullable', 'required_unless:fiscal_printer.driver,fake', self::HOST],
            'fiscal_printer.departments' => 'required|array',
            'fiscal_printer.departments.*' => 'required|integer|min:1|max:99',
            'fiscal_printer.payment_cash' => 'required|integer|min:1|max:99',
            'fiscal_printer.payment_card' => 'required|integer|min:1|max:99',

            'vne.driver' => ['required', Rule::in(array_keys(self::DRIVERS['vne']))],
            'vne.host' => ['nullable', 'required_if:vne.driver,direct', self::HOST],
            'vne.op_name' => 'nullable|string|max:30',

            'pos.driver' => ['required', Rule::in(array_keys(self::DRIVERS['pos']))],
            'terminals' => 'array',
            'terminals.*.name' => 'required|string|max:40',
            'terminals.*.id' => 'required|alpha_num|max:30|distinct',
            'terminals.*.api_key' => 'nullable|string|max:200',
            'terminals.*.active' => 'boolean',
            'terminals.*.original' => 'nullable|string',

            'fattura24.api_key' => 'nullable|string|max:200',
            'fattura24.id_numerator' => 'nullable|string|max:50',

            'closure.reminder_at' => 'required|date_format:H:i',
            'closure.alert_at' => 'required|date_format:H:i',
            'closure.alert_emails' => 'nullable|string|max:500',

            'tea.url' => 'nullable|url|max:200',
            'tea.user' => 'nullable|string|max:100',
            'tea.password' => 'nullable|string|max:200',
            'tea.shipping.company' => 'required|string|max:100',
            'tea.shipping.street' => 'required|string|max:100',
            'tea.shipping.city' => 'required|string|max:60',
            'tea.shipping.postcode' => 'required|string|max:10',
            'tea.shipping.region' => 'required|string|max:5',
        ], [
            'terminals.*.id.distinct' => 'Lo stesso TID compare due volte.',
            'fiscal_printer.host.regex' => 'Indirizzo della stampante non valido: scrivi solo IP o nome, ad esempio 192.168.1.50 (eventualmente :porta).',
            'vne.host.regex' => 'Indirizzo del cassetto non valido: scrivi solo IP o nome, ad esempio 192.168.1.60 (eventualmente :porta).',
        ], [
            'fiscal_printer.host' => 'indirizzo della stampante',
            'vne.host' => 'indirizzo del cassetto',
            'terminals.*.name' => 'nome del terminale',
            'terminals.*.id' => 'TID',
        ])->after(function ($validator) use ($request) {
            $emails = array_filter(array_map('trim', explode(',', (string) $request->input('closure.alert_emails'))));
            foreach ($emails as $email) {
                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $validator->errors()->add('closure.alert_emails', "Indirizzo email non valido: {$email}");
                }
            }
        })->validate();

        // Chiavi lasciate vuote = restano quelle salvate
        $oldKeys = collect(CloudPos::configuredTerminals())->pluck('api_key', 'id');
        $terminals = collect($data['terminals'] ?? [])->map(fn ($t) => [
            'id' => trim($t['id']),
            'name' => trim($t['name']),
            'api_key' => trim((string) ($t['api_key'] ?? '')) ?: ($oldKeys[$t['original'] ?? ''] ?? null),
            'active' => (bool) ($t['active'] ?? false),
        ])->values()->all();

        $departments = config('orio.fiscal_printer.departments');
        foreach (self::VAT_RATES as $rate) {
            $departments[$rate] = (int) $data['fiscal_printer']['departments'][$rate];
        }

        $values = [
            'company.name' => $data['company']['name'],
            'company.address' => $data['company']['address'] ?? null,
            'company.vat_code' => $data['company']['vat_code'] ?? null,
            'fiscal_printer.driver' => $data['fiscal_printer']['driver'],
            'fiscal_printer.host' => $data['fiscal_printer']['host'] ?? null,
            'fiscal_printer.departments' => $departments,
            'fiscal_printer.payment_cash' => $data['fiscal_printer']['payment_cash'],
            'fiscal_printer.payment_card' => $data['fiscal_printer']['payment_card'],
            'vne.driver' => $data['vne']['driver'],
            'vne.host' => $data['vne']['host'] ?? null,
            'vne.op_name' => $data['vne']['op_name'] ?? null,
            'pos.driver' => $data['pos']['driver'],
            'pos.terminals' => $terminals,
            'fattura24.id_numerator' => $data['fattura24']['id_numerator'] ?? null,
            'closure.reminder_at' => $data['closure']['reminder_at'],
            'closure.alert_at' => $data['closure']['alert_at'],
            'closure.alert_emails' => array_values(array_filter(array_map('trim', explode(',', (string) ($data['closure']['alert_emails'] ?? ''))))),
            'tea.url' => $data['tea']['url'] ?? null,
            'tea.user' => $data['tea']['user'] ?? null,
            'tea.shipping.company' => $data['tea']['shipping']['company'],
            'tea.shipping.street' => $data['tea']['shipping']['street'],
            'tea.shipping.city' => $data['tea']['shipping']['city'],
            'tea.shipping.postcode' => $data['tea']['shipping']['postcode'],
            'tea.shipping.region' => strtoupper($data['tea']['shipping']['region']),
        ];
        foreach (['fattura24.api_key', 'tea.password'] as $secret) {
            if (($new = trim((string) data_get($data, $secret))) !== '') {
                $values[$secret] = $new;
            }
        }

        Settings::save($values, $request->user()->id);

        return redirect()->route('settings')->with('status', 'Configurazione salvata.');
    }

    /** Prova della chiave di un terminale gia' salvato, senza pagamenti. */
    public function testPos(Request $request, CloudPos $pos)
    {
        $id = (string) $request->validate(['terminal' => 'required|string'])['terminal'];
        abort_unless(collect(CloudPos::terminals())->contains('id', $id), 422, 'Terminale non salvato o disattivato: salva la configurazione e riprova.');
        if (config('orio.pos.driver') === 'manual') {
            return response()->json(['ok' => false, 'message' => 'Il POS è impostato come "non collegato": scegli "Collegato", salva e riprova.']);
        }

        return response()->json($pos->forTerminal($id)->ping());
    }

    public function testTea(TeaOrderClient $client)
    {
        return response()->json($client->ping());
    }

    /** Solo le ultime 4 cifre di una chiave, per riconoscerla senza mostrarla. */
    private static function hint(?string $secret): ?string
    {
        return $secret ? '••••' . mb_substr($secret, -4) : null;
    }
}
