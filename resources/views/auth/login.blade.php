<x-layout title="Accesso">
    <div class="mx-auto mt-16 max-w-sm">
        <div class="mb-8 text-center">
            <img src="{{ asset('images/logo-elda.png') }}" alt="Le Essenze di Elda" class="mx-auto h-16 w-auto">
            <div class="mt-3 text-stone-500">Cassa Bucato Profumato</div>
        </div>
        <form method="POST" action="{{ route('login') }}" class="card space-y-4 p-6">
            @csrf
            <div>
                <label class="mb-1 block text-sm font-semibold" for="username">Utente</label>
                <input id="username" name="username" value="{{ old('username') }}" class="input" autocapitalize="none" autocomplete="username" autofocus required>
            </div>
            <div>
                <label class="mb-1 block text-sm font-semibold" for="password">Password</label>
                <input id="password" type="password" name="password" class="input" autocomplete="current-password" required>
            </div>
            @error('username')
                <p class="text-sm text-red-600">{{ $message }}</p>
            @enderror
            <button class="btn-primary w-full">Entra</button>
        </form>
    </div>
</x-layout>
