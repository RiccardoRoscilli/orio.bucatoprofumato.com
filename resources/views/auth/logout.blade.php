<x-layout title="Esci">
    <div class="mx-auto mt-10 max-w-sm card p-6 text-center">
        <p class="mb-4 text-stone-600">Collegato come <strong>{{ auth()->user()->name }}</strong>.</p>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="btn-primary w-full">Esci e cambia utente</button>
        </form>
        <a href="{{ route('cassa') }}" class="mt-3 inline-block text-sm font-semibold text-brand-700">Torna alla cassa</a>
    </div>
</x-layout>
