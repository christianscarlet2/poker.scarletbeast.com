<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BonusCode extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'integer',
        'max_claims' => 'integer',
        'claims' => 'integer',
        'enabled' => 'boolean',
        'expires_at' => 'datetime',
    ];

    public function claims(): HasMany
    {
        return $this->hasMany(BonusClaim::class);
    }
}
