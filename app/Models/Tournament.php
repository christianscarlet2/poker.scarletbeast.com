<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tournament extends Model
{
    protected $guarded = [];

    protected $casts = [
        'buy_in' => 'integer',
        'fee' => 'integer',
        'starting_stack' => 'integer',
        'seats_per_table' => 'integer',
        'min_players' => 'integer',
        'max_players' => 'integer',
        'blind_levels' => 'array',
        'payout_pct' => 'array',
        'level' => 'integer',
        'prize_pool' => 'integer',
        'starts_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'level_started_at' => 'datetime',
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(TournamentEntry::class);
    }

    public function tables(): HasMany
    {
        return $this->hasMany(PokerTable::class, 'tournament_id');
    }

    public function currentBlinds(): array
    {
        $levels = $this->blind_levels ?: [['sb' => 25, 'bb' => 50, 'minutes' => 10]];
        return $levels[min($this->level, count($levels) - 1)];
    }
}
