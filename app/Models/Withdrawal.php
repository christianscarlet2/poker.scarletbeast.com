<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Withdrawal extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount_chips' => 'integer',
        'amount_crypto' => 'string',
        'rate_usd' => 'string',
        'network_fee' => 'string',
        'processed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
