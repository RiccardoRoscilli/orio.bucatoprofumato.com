<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\AttendanceDevice;
use App\Models\Badge;
use App\Models\User;
use App\Services\AttendanceExcel;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Presenze col badge. In cassa la cassiera bippa il badge: se non e' in negozio inizia il turno,
 * se c'e' gia' Orio chiede conferma e lo chiude. La pagina Presenze (admin) mostra i turni del
 * mese con le ore, permette le correzioni e l'esportazione in Excel.
 */
class AttendanceController extends Controller
{
    /**
     * Bip del badge in cassa.
     *  - normale: entrata, oppure richiesta di conferma dell'uscita (end=1 per chiudere);
     *  - select=1 (finestra "chi fa cassa?"): sceglie la cassiera, con l'entrata se non era presente.
     */
    public function badge(Request $request)
    {
        $data = $request->validate(['code' => 'required|string|max:40', 'end' => 'boolean', 'select' => 'boolean']);

        // Solo dal tablet abilitato: da un altro dispositivo non si timbra, anche con il login fatto
        $device = AttendanceDevice::fromRequest($request);
        if (! $device) {
            return response()->json([
                'device_required' => true,
                'message' => 'Questo dispositivo non è abilitato per le presenze. Chiedi a un admin il codice di 6 cifre (Gestione > Badge). '
                    . 'Succede anche se sul tablet sono stati cancellati i dati di Chrome.',
            ], 403);
        }
        $device->touchFrom($request);

        $badge = Badge::findByCode($data['code']);
        $user = $badge?->user;
        if (! $badge) {
            return response()->json(['message' => 'Badge non riconosciuto.'], 404);
        }
        if (! $badge->active) {
            return response()->json(['message' => "{$badge->label()} è disattivato (badge perso o sostituito)."], 422);
        }
        if (! $user || ! $user->active) {
            return response()->json(['message' => "{$badge->label()} non è assegnato a nessuno: si assegna da Gestione > Badge."], 422);
        }

        $ip = $request->ip();

        return DB::transaction(function () use ($user, $badge, $data, $device, $ip) {
            $open = Attendance::open()->where('user_id', $user->id)->lockForUpdate()->latest('started_at')->first();

            // Turno di un giorno precedente mai chiuso: si chiude a fine di quel giorno, da controllare
            if ($open && $open->started_at->lt(today())) {
                $open->update(['ended_at' => $open->started_at->copy()->endOfDay(), 'ended_auto' => true]);
                $open = null;
            }

            $person = ['id' => $user->id, 'name' => $user->name];

            if (! $open) {
                $open = Attendance::create(['user_id' => $user->id, 'badge_id' => $badge->id, 'device_id' => $device->id, 'ip' => $ip, 'started_at' => now()]);

                return $this->reply($data['select'] ?? false ? 'selected' : 'started', $person + ['since' => $open->started_at->format('H:i')], "Buongiorno {$user->name}: entrata alle {$open->started_at->format('H:i')}.");
            }

            if ($data['select'] ?? false) {
                return $this->reply('selected', $person + ['since' => $open->started_at->format('H:i')]);
            }

            if (! ($data['end'] ?? false)) {
                return $this->reply('ask_end', $person + ['since' => $open->started_at->format('H:i')]);
            }

            $open->update(['ended_at' => now(), 'end_device_id' => $device->id, 'end_ip' => $ip]);

            return $this->reply('ended', $person, "Arrivederci {$user->name}: uscita alle {$open->ended_at->format('H:i')}, turno di " . self::hours($open->minutes()) . '.');
        });
    }

    private function reply(string $action, array $user, ?string $message = null)
    {
        return response()->json(['action' => $action, 'user' => $user, 'message' => $message, 'present' => Attendance::presentCashiers()]);
    }

    public function index(Request $request)
    {
        $month = $this->month($request);

        $shifts = Attendance::with('user', 'editor', 'badge', 'device', 'endDevice')
            ->where('started_at', '>=', $month)->where('started_at', '<', $month->copy()->addMonth())
            ->orderByDesc('started_at')->get();

        $totals = $shifts->groupBy('user_id')->map(fn ($list) => [
            'name' => $list->first()->user->name,
            'days' => $list->map(fn ($a) => $a->started_at->toDateString())->unique()->count(),
            'minutes' => $list->sum(fn ($a) => $a->minutes()),
            'to_check' => $list->where('ended_auto', true)->count(),
        ])->sortBy('name');

        return view('attendances.index', [
            'month' => $month,
            'shifts' => $shifts,
            'totals' => $totals,
            'present' => Attendance::present(),
            'people' => User::with('badge')->where('active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** Turno aggiunto a mano dall'admin (badge dimenticato). */
    public function store(Request $request)
    {
        $data = $this->validated($request, true);
        Attendance::create($data + ['edited_by' => $request->user()->id]);

        return redirect()->route('attendances.index', ['mese' => Carbon::parse($data['started_at'])->format('Y-m')])->with('status', 'Turno aggiunto.');
    }

    /** Correzione di entrata/uscita: resta scritto chi l'ha fatta. */
    public function update(Request $request, Attendance $attendance)
    {
        $data = $this->validated($request, false);
        $attendance->update($data + ['ended_auto' => false, 'edited_by' => $request->user()->id]);

        return redirect()->route('attendances.index', ['mese' => $attendance->started_at->format('Y-m')])->with('status', "Turno di {$attendance->user->name} corretto.");
    }

    public function destroy(Attendance $attendance)
    {
        $month = $attendance->started_at->format('Y-m');
        $attendance->delete();

        return redirect()->route('attendances.index', ['mese' => $month])->with('status', 'Turno eliminato.');
    }

    public function excel(Request $request)
    {
        $month = $this->month($request);
        $book = app(AttendanceExcel::class)->month($month);

        return response()->streamDownload(function () use ($book) {
            \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($book, 'Xlsx')->save('php://output');
        }, 'presenze-' . $month->format('Y-m') . '.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    private function validated(Request $request, bool $withUser): array
    {
        $data = $request->validate(array_filter([
            'user_id' => $withUser ? 'required|exists:users,id' : null,
            'started_at' => 'required|date',
            'ended_at' => 'nullable|date|after:started_at',
            'note' => 'nullable|string|max:255',
        ]), ['ended_at.after' => "L'uscita deve essere dopo l'entrata."]);

        return array_intersect_key($data, array_flip(['user_id', 'started_at', 'ended_at', 'note'])) + ['ended_at' => null, 'note' => null];
    }

    private function month(Request $request): Carbon
    {
        $value = (string) $request->query('mese', '');

        return preg_match('/^\d{4}-\d{2}$/', $value) ? Carbon::createFromFormat('Y-m-d', "{$value}-01")->startOfDay() : now()->startOfMonth();
    }

    public static function hours(int $minutes): string
    {
        return intdiv($minutes, 60) . ' h ' . str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
    }
}
