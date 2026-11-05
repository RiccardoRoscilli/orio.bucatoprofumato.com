<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Gestione utenti, solo per gli admin: gli operatori (non admin) lavorano solo sulle vendite. */
class UserController extends Controller
{
    public function index()
    {
        $users = User::withCount('sales')->orderByDesc('active')->orderBy('name')->get();

        return view('users.index', compact('users'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        User::create($data);

        return redirect()->route('users.index')->with('status', "Utente {$data['username']} creato.");
    }

    public function update(Request $request, User $user)
    {
        $data = $this->validated($request, $user);

        // Chi gestisce gli utenti non puo' togliersi da solo i permessi o disattivarsi:
        // cosi' resta sempre almeno un admin attivo.
        if ($user->is($request->user()) && (! $data['is_admin'] || ! $data['active'])) {
            return back()->withInput()->withErrors(['is_admin' => 'Non puoi togliere a te stesso il ruolo di admin o disattivarti.']);
        }

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }
        $user->update($data);

        return redirect()->route('users.index')->with('status', "Utente {$user->username} aggiornato.");
    }

    private function validated(Request $request, ?User $user = null): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'username' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9._-]+$/', Rule::unique('users', 'username')->ignore($user?->id)],
            'password' => [$user ? 'nullable' : 'required', 'string', Password::min(6)],
        ], [
            'username.regex' => 'Lo username può contenere solo lettere minuscole, numeri, punto, trattino e underscore.',
            'username.unique' => 'Esiste già un utente con questo username.',
        ], [
            'name' => 'nome', 'username' => 'username', 'password' => 'password',
        ]);

        $data['is_admin'] = $request->boolean('is_admin');
        $data['active'] = $request->boolean('active');

        return $data;
    }
}
