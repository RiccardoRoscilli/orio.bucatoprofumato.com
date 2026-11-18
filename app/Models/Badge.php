<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Badge fisso numerato (Operatore 1, 2, ...) con il suo QR, assegnato a un utente o libero.
 * Il codice e' ORIO + 8 lettere/cifre: solo maiuscole e cifre, perche' il lettore "scrive" come
 * una tastiera e i simboli cambiano con la lingua della tastiera del tablet.
 */
class Badge extends Model
{
    protected $fillable = ['number', 'code', 'user_id', 'active'];

    protected $casts = ['active' => 'boolean'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function label(): string
    {
        return "Operatore {$this->number}";
    }

    public static function newCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // senza 0/O e 1/I, per leggerlo anche a occhio
        do {
            $code = 'ORIO';
            for ($i = 0; $i < 8; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (static::where('code', $code)->exists());

        return $code;
    }

    /** Nuovi badge con i numeri successivi all'ultimo. */
    public static function createNext(int $count): \Illuminate\Support\Collection
    {
        $next = (int) static::max('number') + 1;

        return collect(range($next, $next + $count - 1))
            ->map(fn ($number) => static::create(['number' => $number, 'code' => static::newCode()]));
    }

    public static function findByCode(string $code): ?self
    {
        $code = strtoupper(trim($code));

        return preg_match('/^ORIO[A-Z0-9]{8}$/', $code) ? static::with('user')->where('code', $code)->first() : null;
    }

    /**
     * Assegna il badge (null = libero). Chi lo aveva e risulta ancora in negozio: turno chiuso
     * adesso e segnato da controllare. Chi riceve il badge lascia l'eventuale badge precedente.
     *
     * @return string messaggio per l'admin
     */
    public function assignTo(?User $user): string
    {
        $previous = $this->user;
        if ($previous && $previous->isNot($user)) {
            Attendance::open()->where('user_id', $previous->id)->update(['ended_at' => now(), 'ended_auto' => true]);
        }

        $freed = null;
        if ($user) {
            $freed = static::where('user_id', $user->id)->whereKeyNot($this->id)->first();
            $freed?->update(['user_id' => null]);
        }
        $this->update(['user_id' => $user?->id]);

        return match (true) {
            ! $user => "{$this->label()} ora è libero" . ($previous ? " (era di {$previous->name})." : '.'),
            (bool) $freed => "{$this->label()} assegnato a {$user->name}; il suo {$freed->label()} ora è libero.",
            default => "{$this->label()} assegnato a {$user->name}.",
        };
    }
}
