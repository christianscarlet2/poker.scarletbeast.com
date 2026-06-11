<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Seat extends Model
{
    protected $guarded = [];

    protected $casts = [
        'seat_no' => 'integer',
        'stack' => 'integer',
        'is_bot' => 'boolean',
        'joined_at' => 'datetime',
    ];

    public function table(): BelongsTo
    {
        return $this->belongsTo(PokerTable::class, 'table_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOccupied(): bool
    {
        return $this->status !== 'empty' && $this->user_id !== null;
    }
}
