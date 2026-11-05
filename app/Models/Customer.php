<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $fillable = [
        'name', 'vat_code', 'fiscal_code', 'address', 'postcode', 'city',
        'province', 'country', 'sdi_code', 'pec', 'email', 'phone',
    ];

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }
}
