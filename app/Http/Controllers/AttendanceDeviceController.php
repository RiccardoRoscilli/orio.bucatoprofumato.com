<?php

namespace App\Http\Controllers;

use App\Models\AttendanceDevice;
use Illuminate\Http\Request;

/**
 * Abilitazione dei dispositivi alle presenze: dal dispositivo stesso con un admin collegato,
 * oppure a distanza con un codice di 6 cifre generato da un admin e scritto sul tablet.
 */
class AttendanceDeviceController extends Controller
{
    /** Admin sul tablet: abilita il dispositivo che sta usando. */
    public function enableThis(Request $request)
    {
        $name = $request->validate(['name' => 'required|string|max:60'])['name'];
        AttendanceDevice::enable($name, $request->user()->id, 'pulsante');

        return redirect()->route('badges.index')->with('status', "Questo dispositivo ({$name}) ora registra le presenze.");
    }

    /** Admin da qualsiasi dispositivo: codice da dettare a chi e' davanti al tablet. */
    public function code(Request $request)
    {
        ['code' => $code, 'expires' => $expires] = AttendanceDevice::issueCode($request->user()->id);

        return redirect()->route('badges.index')->with('device_code', ['code' => $code, 'expires' => $expires->format('H:i')]);
    }

    public function revoke(Request $request, AttendanceDevice $device)
    {
        $device->update(['revoked_at' => now(), 'revoked_by' => $request->user()->id]);

        return redirect()->route('badges.index')->with('status', "{$device->name}: abilitazione revocata, non registra più le presenze.");
    }

    /** Sul tablet (qualsiasi utente collegato): il codice dell'admin abilita il dispositivo. */
    public function activate(Request $request)
    {
        $data = $request->validate(['code' => 'required|string|max:12', 'name' => 'nullable|string|max:60']);

        $adminId = AttendanceDevice::redeemCode($data['code']);
        if (! $adminId) {
            return response()->json(['message' => 'Codice non valido o scaduto: fatti dare un codice nuovo da un admin (Gestione > Badge).'], 422);
        }

        $device = AttendanceDevice::enable($data['name'] ?? 'Tablet cassa', $adminId, 'codice');

        return response()->json(['ok' => true, 'message' => "Tablet abilitato alle presenze ({$device->name})."]);
    }
}
