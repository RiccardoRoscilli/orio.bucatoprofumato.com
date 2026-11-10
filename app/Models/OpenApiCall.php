<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class OpenApiCall extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'openapi_calls';

    protected $guarded = [];

    protected $casts = [
        'live' => 'boolean',
        'charged' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Chiamate del mese indicato ("2026-11"). */
    public function scopeInMonth(Builder $query, string $month): Builder
    {
        $start = Carbon::createFromFormat('Y-m-d', $month . '-01')->startOfMonth();

        return $query->whereBetween('created_at', [$start, $start->copy()->endOfMonth()]);
    }

    /**
     * Una chiamata e' addebitata da OpenAPI quando l'API in produzione risponde con
     * successo; sandbox, errori e partite IVA non trovate non costano.
     */
    public static function record(string $service, string $vatCode, ?int $status, bool $live): self
    {
        return static::create([
            'user_id' => auth()->id(),
            'service' => $service,
            'vat_code' => $vatCode,
            'http_status' => $status,
            'live' => $live,
            'charged' => $live && $status !== null && $status >= 200 && $status < 300,
        ]);
    }
}
