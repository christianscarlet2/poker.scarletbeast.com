<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deposit extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount_crypto' => 'string',
        'rate_usd' => 'string',
        'amount_chips' => 'integer',
        'confirmations' => 'integer',
        'credited_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(DepositAddress::class, 'deposit_address_id');
    }
}
