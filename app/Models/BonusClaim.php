<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BonusClaim extends Model
{
    protected $guarded = [];

    protected $casts = ['amount' => 'integer'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bonusCode(): BelongsTo
    {
        return $this->belongsTo(BonusCode::class);
    }
}
