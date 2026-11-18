<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Ordine di riassortimento del negozio inviato a TEA (teaerp). */
class TeaOrder extends Model
{
    public const STATUSES = ['aperto' => 'In preparazione', 'bozza' => 'Bozza', 'inviato' => 'Inviato a TEA', 'errore' => 'Non inviato'];

    protected $fillable = [
        'reference', 'status', 'tea_increment_id', 'tea_status', 'subtotal_cents', 'tax_cents', 'total_cents',
        'notes', 'error', 'response', 'user_id', 'updated_by', 'sent_at', 'status_checked_at',
    ];

    protected $casts = [
        'response' => 'array',
        'sent_at' => 'datetime',
        'status_checked_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(TeaOrderItem::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function editor()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * A che punto e' la merce, dai DDT di TEA e dalle spunte in negozio: in attesa di spedizione,
     * da spuntare, spuntato in parte, spuntato tutto (da confermare), carico confermato. Se TEA ha
     * spedito meno di quanto ordinato si aggiunge che manca merce. Servono items e ddts.items caricati.
     *
     * @return array{key:string, label:string, missing:bool}
     */
    public function arrival(): array
    {
        if ($this->status !== 'inviato') {
            return ['key' => 'errore', 'label' => self::STATUSES[$this->status] ?? $this->status, 'missing' => false];
        }
        $rows = $this->ddts->flatMap->items->filter(fn ($i) => $i->product_id);
        if ($rows->isEmpty()) {
            return ['key' => 'attesa', 'label' => 'Confermato, in attesa di spedizione', 'missing' => false];
        }
        $shipped = $this->ddts->flatMap->items->groupBy('sku')->map->sum('qty_shipped');
        $missing = $this->items->contains(fn ($i) => ($shipped[$i->sku] ?? 0) < $i->qty);
        $checked = $rows->whereNotNull('qty_received')->count();

        [$key, $label] = match (true) {
            $checked === 0 => ['spuntare', 'Spedito, da spuntare'],
            $checked < $rows->count() => ['in_parte', 'Spuntato in parte'],
            $this->ddts->contains(fn ($d) => ! $d->confirmed_at) => ['spuntato', 'Spuntato tutto, carico da confermare'],
            default => ['confermato', 'Completo'],
        };

        return ['key' => $key, 'label' => $label . ($missing ? ' · manca merce' : ''), 'missing' => $missing];
    }

    /** L'ordine in preparazione del negozio (uno solo, condiviso da tutti), se c'e'. */
    public static function open(): ?self
    {
        return static::where('status', 'aperto')->latest('id')->first();
    }

    public function ddts()
    {
        return $this->hasMany(TeaDdt::class)->orderBy('id');
    }

    /**
     * DDT letti da teaerp: aggiunge solo quelli nuovi (una spedizione parziale arrivata dopo),
     * quelli gia' presenti restano come sono con le righe gia' spuntate. Ritorna quanti sono nuovi.
     */
    public function syncDdts(array $ddts, array $perBox = []): int
    {
        $known = $this->ddts()->pluck('ddt_number')->all();
        $new = 0;

        foreach ($ddts as $data) {
            $number = trim((string) ($data['ddt_number'] ?? ''));
            if ($number === '' || in_array($number, $known, true)) {
                continue;
            }

            \Illuminate\Support\Facades\DB::transaction(function () use ($data, $number, $perBox) {
                $ddt = $this->ddts()->create(['ddt_number' => $number, 'issued_at' => $data['issued_at'] ?? null]);
                $products = Product::whereIn('sku', collect($data['items'] ?? [])->pluck('sku')->filter()->all())->get()->keyBy('sku');
                foreach ($data['items'] ?? [] as $item) {
                    // Scatola di TEA; se ha spedito pezzi sciolti la riga si spunta a pezzi
                    $shipped = (float) ($item['qty_shipped'] ?? 0);
                    $box = $perBox[trim((string) ($item['sku'] ?? ''))] ?? 1;
                    $box = fmod($shipped, $box) == 0 ? $box : 1;
                    $ddt->items()->create([
                        'pcs_per_box' => $box,
                        'product_id' => $products->get($item['sku'] ?? '')?->id,
                        'sku' => $item['sku'] ?? null,
                        'name' => $item['name'] ?? null,
                        'qty_shipped' => (float) ($item['qty_shipped'] ?? 0),
                    ]);
                }
            });
            $known[] = $number;
            $new++;
        }

        return $new;
    }
}
