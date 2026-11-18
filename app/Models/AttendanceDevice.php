<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Dispositivo abilitato alle presenze. La chiave sta in un cookie del dispositivo (cifrato da
 * Laravel, non leggibile dagli script, solo https); il server ne tiene solo l'impronta SHA-256.
 * Chrome non tiene cookie oltre 400 giorni: la scadenza si rinnova a ogni bip.
 */
class AttendanceDevice extends Model
{
    public const COOKIE = 'orio_device';
    public const COOKIE_MINUTES = 400 * 24 * 60;
    public const CODE_MINUTES = 10;

    protected $fillable = ['name', 'token_hash', 'enabled_by', 'enabled_via', 'last_used_at', 'last_ip', 'revoked_at', 'revoked_by'];

    protected $casts = ['last_used_at' => 'datetime', 'revoked_at' => 'datetime'];

    public function enabler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enabled_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /** Il dispositivo da cui arriva la richiesta, se abilitato e non revocato. */
    public static function fromRequest(Request $request): ?self
    {
        $token = $request->cookie(self::COOKIE);
        if (! is_string($token) || strlen($token) < 40) {
            return null;
        }

        return static::where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->first();
    }

    /** Abilita il dispositivo della richiesta: nuova chiave nel suo cookie. */
    public static function enable(string $name, int $userId, string $via): self
    {
        $token = Str::random(64);
        $device = static::create(['name' => $name, 'token_hash' => hash('sha256', $token), 'enabled_by' => $userId, 'enabled_via' => $via]);
        self::queueCookie($token);

        return $device;
    }

    /** Ultimo utilizzo e scadenza del cookie allungata di altri 400 giorni. */
    public function touchFrom(Request $request): void
    {
        $this->update(['last_used_at' => now(), 'last_ip' => $request->ip()]);
        self::queueCookie((string) $request->cookie(self::COOKIE));
    }

    private static function queueCookie(string $token): void
    {
        Cookie::queue(Cookie::make(self::COOKIE, $token, self::COOKIE_MINUTES, '/', null, request()->isSecure() || str_starts_with((string) config('app.url'), 'https://'), true, false, 'lax'));
    }

    /**
     * Codice di 6 cifre per abilitare un dispositivo a distanza: valido 10 minuti, una volta sola.
     *
     * @return array{code:string, expires:\Illuminate\Support\Carbon}
     */
    public static function issueCode(int $adminId): array
    {
        do {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (Cache::has("device_code:{$code}"));

        $expires = now()->addMinutes(self::CODE_MINUTES);
        Cache::put("device_code:{$code}", ['by' => $adminId], $expires);

        return ['code' => $code, 'expires' => $expires];
    }

    /** Usa il codice (una volta sola): ritorna l'admin che l'ha creato, o null se non valido. */
    public static function redeemCode(string $code): ?int
    {
        $code = preg_replace('/\D/', '', $code);

        return strlen($code) === 6 ? (Cache::pull("device_code:{$code}")['by'] ?? null) : null;
    }
}
