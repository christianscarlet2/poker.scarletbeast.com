<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Stake extends Model
{
    protected $guarded = [];

    protected $casts = [
        'small_blind' => 'integer',
        'big_blind' => 'integer',
        'min_buy_in' => 'integer',
        'max_buy_in' => 'integer',
        'max_seats' => 'integer',
        'enabled' => 'boolean',
    ];

    public function tables(): HasMany
    {
        return $this->hasMany(PokerTable::class);
    }
}
