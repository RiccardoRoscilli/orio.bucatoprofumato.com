<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Simulatore minimo delle API WEB della Axon DADO RT (solo in locale), per provare il driver
 * "axon" senza la stampante: FISCAL_PRINTER_HOST=orio.test/dev/axon.
 * Una riga di vendita con descrizione che contiene "ERRORE" viene rifiutata (errore prezzo).
 */
class FakeAxonController extends Controller
{
    private const KEY = 'axon_fake:state';

    public function io(Request $request)
    {
        $state = Cache::get(self::KEY, ['zno' => 1, 'docno' => 0, 'open' => false, 'lines' => []]);

        if ($request->query('cmd') === '0') {
            return response()->json([
                'display' => ['lines' => ['ORIO SIMULATORE', $state['open'] ? 'DOCUMENTO APERTO' : '']],
                'rtc' => ['date' => now()->format('d-m-Y'), 'time' => now()->format('H:i')],
                'ej' => ['status' => '1100000', 'lastdoc' => ['zno' => $state['zno'], 'docno' => $state['docno']]],
            ]);
        }

        abort_unless($request->query('cmd') === '4', 400);

        $fields = explode('/', (string) $request->query('pkt'));
        $code = '00';

        switch ($fields[0]) {
            case '3':
                if (str_contains(strtoupper($fields[2] ?? ''), 'ERRORE')) {
                    $code = '18';
                    break;
                }
                $state['open'] = true;
                $state['lines'][] = $request->query('pkt');
                break;
            case '5':
                if (! $state['open']) {
                    $code = '1B';
                    break;
                }
                $state['docno']++;
                $state['open'] = false;
                $state['lines'] = [];
                break;
            case '+':
                $state['open'] = false;
                $state['lines'] = [];
                break;
            case 'x':
                if (($fields[1] ?? '') === '7') {
                    if ($state['open']) {
                        $code = '14';
                        break;
                    }
                    $state['zno']++;
                    $state['docno'] = 0;
                }
                break;
            case '?':
                break;
            default:
                $code = '06';
        }

        Cache::forever(self::KEY, $state);

        return response($code . '/0' . ($state['open'] ? '6' : '2') . '/00/', 200, ['Content-Type' => 'text/plain']);
    }
}
