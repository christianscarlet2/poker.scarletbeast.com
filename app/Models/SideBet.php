<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SideBet extends Model
{
    protected $guarded = [];

    protected $casts = [
        'hand_no' => 'integer',
        'stake' => 'integer',
        'odds_x100' => 'integer',
        'payout' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(PokerTable::class, 'table_id');
    }
}
