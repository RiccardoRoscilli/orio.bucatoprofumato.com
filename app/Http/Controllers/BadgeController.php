<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\AttendanceDevice;
use App\Models\Badge;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Badge fissi numerati: si creano e si stampano una volta, poi si assegnano agli utenti.
 * Quando una dipendente va via il badge si libera e passa a un'altra; i turni gia' registrati
 * restano a chi li ha fatti.
 */
class BadgeController extends Controller
{
    public function index()
    {
        return view('badges.index', [
            'badges' => Badge::with('user')->orderBy('number')->get(),
            'users' => User::with('badge')->where('active', true)->orderBy('name')->get(['id', 'name']),
            'present' => Attendance::open()->pluck('user_id')->all(),
            'devices' => AttendanceDevice::with('enabler', 'revoker')->latest()->get(),
            'thisDevice' => AttendanceDevice::fromRequest(request()),
        ]);
    }

    public function store(Request $request)
    {
        $count = (int) $request->validate(['count' => 'required|integer|min:1|max:50'])['count'];
        $badges = Badge::createNext($count);

        return redirect()->route('badges.index')->with('status', $count === 1
            ? "Creato {$badges->first()->label()}: stampalo da qui."
            : "Creati i badge da {$badges->first()->number} a {$badges->last()->number}: stampali da qui.");
    }

    /** Assegna a un utente (o libera) il badge. */
    public function update(Request $request, Badge $badge)
    {
        $data = $request->validate(['user_id' => 'nullable|exists:users,id']);
        $user = isset($data['user_id']) ? User::find($data['user_id']) : null;
        abort_if($user && ! $user->active, 422, 'Utente disattivato.');

        return redirect()->route('badges.index')->with('status', $badge->assignTo($user));
    }

    /** Badge perso: quello vecchio smette di funzionare, se ne stampa uno nuovo con lo stesso numero. */
    public function replace(Badge $badge)
    {
        $badge->update(['code' => Badge::newCode(), 'active' => true]);

        return redirect()->route('badges.print', ['id' => $badge->id])
            ->with('status', "Nuovo {$badge->label()}: stampalo, quello vecchio non funziona più.");
    }

    /** Stampa dei badge (tutti, oppure uno solo con ?id=). */
    public function print(Request $request)
    {
        $badges = Badge::where('active', true)->orderBy('number')
            ->when($request->integer('id'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        return view('badges.print', ['badges' => $badges]);
    }
}
