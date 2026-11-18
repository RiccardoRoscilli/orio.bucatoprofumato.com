<x-layout :title="$product->exists ? 'Modifica prodotto' : 'Nuovo prodotto'">
    <a href="{{ $product->exists ? route('products.show', $product) : route('products.index') }}"
       class="mb-4 inline-flex items-center gap-1 text-sm font-semibold text-stone-600 hover:text-stone-900">← {{ $product->exists ? trim($product->name) : 'Prodotti' }}</a>

    <h1 class="text-xl font-bold">{{ $product->exists ? 'Modifica prodotto' : 'Nuovo prodotto solo negozio' }}</h1>
    <p class="mt-1 text-sm text-stone-600">
        Per i prodotti che si vendono solo a Orio e non sono su bucatoprofumato.com (es. shopper):
        la sincronizzazione con il sito non li modifica e non li disattiva.
    </p>

    <form method="POST" action="{{ $product->exists ? route('products.update', $product) : route('products.store') }}" enctype="multipart/form-data" class="card mt-4 max-w-2xl space-y-4 p-5">
        @csrf
        @if ($product->exists) @method('PUT') @endif

        @if ($errors->any())
            <p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ $errors->first() }}</p>
        @endif

        <label class="block text-sm font-semibold">Nome
            <input name="name" value="{{ old('name', $product->name) }}" maxlength="255" required autofocus class="input mt-1" placeholder="Shopper carta grande">
        </label>

        <div class="grid gap-4 sm:grid-cols-2">
            <label class="block text-sm font-semibold">Prezzo in cassa (€)
                <input name="price" value="{{ old('price', $product->price_cents !== null ? number_format($product->price_cents / 100, 2, ',', '') : '') }}"
                       inputmode="decimal" required class="input mt-1" placeholder="0,50">
            </label>
            <label class="block text-sm font-semibold">IVA
                <select name="vat_rate" class="input mt-1">
                    @foreach ($vatRates as $rate)
                        <option value="{{ $rate }}" @selected((int) old('vat_rate', $product->vat_rate) === $rate)>{{ $rate }}%</option>
                    @endforeach
                </select>
            </label>
        </div>

        <label class="block text-sm font-semibold">Codice a barre (EAN)
            <input name="ean" value="{{ old('ean', $product->ean) }}" inputmode="numeric" maxlength="14" class="input mt-1 font-mono" placeholder="Bippalo qui, se ce l'ha">
            <span class="mt-1 block text-xs font-normal text-stone-500">Facoltativo: senza codice il prodotto si trova in cassa cercandolo per nome.</span>
        </label>

        <div class="grid gap-4 sm:grid-cols-2">
            <label class="block text-sm font-semibold">Codice interno (SKU)
                <input name="sku" value="{{ old('sku', $product->sku) }}" maxlength="64" class="input mt-1 font-mono" placeholder="automatico">
            </label>
            <label class="block text-sm font-semibold">Formato
                <input name="format" value="{{ old('format', $product->format) }}" maxlength="60" class="input mt-1" placeholder="es. 30×40 cm">
            </label>
        </div>

        <div class="text-sm font-semibold" x-data="{ preview: @js($product->image_url), remove: false }">
            Foto
            <div class="mt-1 flex items-center gap-4">
                <div class="flex h-24 w-24 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-stone-100 ring-1 ring-stone-200">
                    <img x-show="preview && !remove" :src="preview" alt="" class="max-h-full max-w-full object-contain">
                    <span x-show="!preview || remove" class="text-xs font-normal text-stone-400">Nessuna foto</span>
                </div>
                <div class="space-y-2">
                    <input type="file" name="image" accept="image/jpeg,image/png,image/webp" class="block text-sm font-normal text-stone-600 file:mr-3 file:cursor-pointer file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:font-semibold file:text-brand-700 hover:file:bg-brand-100"
                           @change="const f = $event.target.files[0]; if (f) { preview = URL.createObjectURL(f); remove = false }">
                    @if ($product->image_url)
                        <label class="flex items-center gap-2 font-normal text-stone-600">
                            <input type="checkbox" name="remove_image" value="1" x-model="remove"> Togli la foto
                        </label>
                    @endif
                    <span class="block text-xs font-normal text-stone-500">JPG, PNG o WEBP, massimo 8 MB. Si vede nella scheda del prodotto.</span>
                </div>
            </div>
        </div>

        <div class="flex gap-2 pt-2">
            <button class="btn-primary">{{ $product->exists ? 'Salva' : 'Crea prodotto' }}</button>
            <a href="{{ $product->exists ? route('products.show', $product) : route('products.index') }}" class="btn-light">Annulla</a>
        </div>
    </form>
</x-layout>
