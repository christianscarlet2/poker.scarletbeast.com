<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PokerTable extends Model
{
    protected $guarded = [];

    protected $casts = [
        'small_blind' => 'integer',
        'big_blind' => 'integer',
        'min_buy_in' => 'integer',
        'max_buy_in' => 'integer',
        'max_seats' => 'integer',
        'hand_no' => 'integer',
        'is_auto' => 'boolean',
        'last_action_at' => 'datetime',
    ];

    public function stake(): BelongsTo
    {
        return $this->belongsTo(Stake::class);
    }

    public function seats(): HasMany
    {
        return $this->hasMany(Seat::class, 'table_id');
    }

    public function state(): HasOne
    {
        return $this->hasOne(TableState::class, 'table_id');
    }

    public function hands(): HasMany
    {
        return $this->hasMany(Hand::class, 'table_id');
    }

    public function occupiedSeats()
    {
        return $this->seats()->where('status', '!=', 'empty');
    }

    public function bots(): bool
    {
        return $this->table_type !== 'human_only';
    }

    public function allowsHumans(): bool
    {
        return $this->table_type !== 'machine_only';
    }
}
