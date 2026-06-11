<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CasinoRound extends Model
{
    protected $guarded = [];

    protected $casts = [
        'state' => 'array',
        'outcome' => 'array',
        'wagered' => 'integer',
        'paid' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
