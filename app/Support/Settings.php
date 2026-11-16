<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Configurazione modificabile dall'admin (pagina Configurazione) sopra al .env: quello che e'
 * salvato qui sostituisce il valore del .env in config('orio.…'), quindi il resto di Orio non
 * cambia. Chiavi e password sono cifrate nel database e non tornano mai intere nella pagina.
 */
class Settings
{
    private const CACHE = 'orio.settings';

    /** Voci configurabili: percorso dentro config('orio') => tipo di valore. */
    public const FIELDS = [
        'company.name' => 'text',
        'company.address' => 'text',
        'company.vat_code' => 'text',

        'fiscal_printer.driver' => 'text',
        'fiscal_printer.host' => 'text',
        'fiscal_printer.departments' => 'departments',
        'fiscal_printer.payment_cash' => 'int',
        'fiscal_printer.payment_card' => 'int',

        'vne.driver' => 'text',
        'vne.host' => 'text',
        'vne.scheme' => 'text',
        'vne.op_name' => 'text',

        'pos.driver' => 'text',
        'pos.terminals' => 'terminals',

        'fattura24.api_key' => 'secret',
        'fattura24.id_numerator' => 'text',

        'closure.reminder_at' => 'text',
        'closure.alert_at' => 'text',
        'closure.alert_emails' => 'list',

        'tea.url' => 'text',
        'tea.user' => 'text',
        'tea.password' => 'secret',
        'tea.shipping.company' => 'text',
        'tea.shipping.street' => 'text',
        'tea.shipping.city' => 'text',
        'tea.shipping.postcode' => 'text',
        'tea.shipping.region' => 'text',
    ];

    /** Applica le voci salvate sopra alla configurazione del .env (all'avvio di ogni richiesta). */
    public static function apply(): void
    {
        try {
            $rows = Cache::rememberForever(self::CACHE, fn () => Setting::pluck('value', 'key')->all());
        } catch (\Throwable) {
            return; // tabella non ancora creata (installazione, test): resta il .env
        }

        foreach ($rows as $key => $raw) {
            if (! isset(self::FIELDS[$key])) {
                continue;
            }
            try {
                config(['orio.' . $key => self::decode(self::FIELDS[$key], $raw)]);
            } catch (\Throwable) {
                // valore illeggibile (es. APP_KEY cambiata): resta quello del .env
            }
        }
    }

    /** Salva le voci date (gia' nel formato di config) e le rende subito attive. */
    public static function save(array $values, ?int $userId): void
    {
        foreach ($values as $key => $value) {
            if (! isset(self::FIELDS[$key])) {
                throw new \InvalidArgumentException("Voce di configurazione sconosciuta: {$key}");
            }
            Setting::updateOrCreate(['key' => $key], ['value' => self::encode(self::FIELDS[$key], $value), 'updated_by' => $userId]);
        }
        Cache::forget(self::CACHE);
        self::apply();
    }

    public static function isStored(string $key): bool
    {
        return Setting::whereKey($key)->exists();
    }

    /** "22:1,10:2" => [22 => 1, 10 => 2]: aliquota IVA => reparto della stampante */
    public static function parseDepartments(string $value): array
    {
        return collect(explode(',', $value))
            ->map(fn ($pair) => array_map('trim', explode(':', $pair)))
            ->filter(fn ($p) => count($p) === 2 && is_numeric($p[0]) && is_numeric($p[1]))
            ->mapWithKeys(fn ($p) => [(int) $p[0] => (int) $p[1]])
            ->all();
    }

    private static function encode(string $type, mixed $value): ?string
    {
        return match ($type) {
            'secret' => $value === null || $value === '' ? null : Crypt::encryptString((string) $value),
            'terminals' => Crypt::encryptString(json_encode(array_values($value))),
            'departments' => collect($value)->map(fn ($dep, $vat) => "{$vat}:{$dep}")->implode(','),
            'list' => implode(',', $value),
            default => $value === null ? null : (string) $value,
        };
    }

    private static function decode(string $type, ?string $raw): mixed
    {
        return match ($type) {
            'secret' => $raw === null ? null : Crypt::decryptString($raw),
            'terminals' => json_decode(Crypt::decryptString($raw), true),
            'departments' => self::parseDepartments((string) $raw),
            'list' => array_values(array_filter(array_map('trim', explode(',', (string) $raw)))),
            'int' => (int) $raw,
            default => $raw,
        };
    }
}
