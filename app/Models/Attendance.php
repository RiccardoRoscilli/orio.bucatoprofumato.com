<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un turno di presenza: entrata e uscita bippate col badge (o corrette dall'admin). */
class Attendance extends Model
{
    protected $fillable = ['user_id', 'badge_id', 'device_id', 'ip', 'started_at', 'ended_at', 'end_device_id', 'end_ip', 'ended_auto', 'edited_by', 'note'];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'ended_auto' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function badge(): BelongsTo
    {
        return $this->belongsTo(Badge::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(AttendanceDevice::class, 'device_id');
    }

    public function endDevice(): BelongsTo
    {
        return $this->belongsTo(AttendanceDevice::class, 'end_device_id');
    }

    /** Da dove sono arrivate entrata e uscita (per i dubbi): dispositivo e IP. */
    public function origin(): string
    {
        $part = fn ($device, $ip) => trim(($device?->name ?? 'a mano') . ($ip ? " ({$ip})" : ''));

        return 'Entrata: ' . $part($this->device, $this->ip) . ($this->ended_at ? ' · Uscita: ' . ($this->ended_auto ? 'chiusa da Orio' : $part($this->endDevice, $this->end_ip)) : '');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    /** Minuti lavorati (turno ancora aperto: fino ad adesso). */
    public function minutes(): int
    {
        return (int) $this->started_at->diffInMinutes($this->ended_at ?? now());
    }

    /** Chi e' in negozio adesso, in ordine di entrata. */
    public static function present()
    {
        return static::open()->with('user')->orderBy('started_at')->get();
    }

    /** Cassiere presenti per la cassa: id, nome, ora di entrata. */
    public static function presentCashiers(): array
    {
        return static::present()->map(fn (self $a) => [
            'id' => $a->user_id,
            'name' => $a->user->name,
            'since' => $a->started_at->format('H:i'),
        ])->values()->all();
    }

    /**
     * Le vendite vanno intestate a una cassiera presente appena qualcuno ha il badge: prima dei
     * badge la cassa lavora come sempre, con l'utente collegato.
     */
    public static function required(): bool
    {
        return Badge::where('active', true)->whereHas('user', fn ($q) => $q->where('active', true))->exists();
    }

    /** Uscite non bippate: Orio chiude i turni aperti e li segnala all'admin. */
    /** Chiude in automatico i turni aperti; $exceptUserId resta aperto (esce subito dopo con il badge). */
    public static function closeOpen(?\DateTimeInterface $at = null, ?int $exceptUserId = null): int
    {
        return static::open()->when($exceptUserId, fn ($q) => $q->where('user_id', '!=', $exceptUserId))
            ->update(['ended_at' => $at ?? now(), 'ended_auto' => true]);
    }
}
