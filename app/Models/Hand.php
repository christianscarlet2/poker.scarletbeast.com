<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Hand extends Model
{
    protected $guarded = [];

    protected $casts = [
        'seats' => 'array',
        'board' => 'array',
        'hole_cards' => 'array',
        'actions' => 'array',
        'winners' => 'array',
        'pot' => 'integer',
        'rake' => 'integer',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function table(): BelongsTo
    {
        return $this->belongsTo(PokerTable::class, 'table_id');
    }
}
