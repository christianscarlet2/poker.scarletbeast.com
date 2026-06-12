<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['username', 'name', 'email', 'password', 'is_admin', 'is_bot', 'chips', 'api_token_hash', 'bot_engine', 'avatar', 'oauth', 'hud_profile_id', 'referral_code', 'referred_by', 'rakeback_accrued', 'affiliate_accrued', 'rakeback_lifetime', 'affiliate_lifetime'])]
#[Hidden(['password', 'remember_token', 'api_token_hash'])]
class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'bot_seen_at' => 'datetime',
            'human_seen_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_bot' => 'boolean',
            'chips' => 'integer',
            'oauth' => 'array',
        ];
    }

    /** Seconds within which a "seen" stamp still counts as live activity. */
    public const SEEN_WINDOW = 30;

    /**
     * Who is driving this account right now — a machine (API token) or a human
     * (web session)? House-bot accounts are always 'bot'. Otherwise we compare
     * the last machine poll/act against the last human poll/act, so the answer
     * flips the instant the other side takes over the seat. Returns 'bot'|'human'.
     */
    public function playingAs(): string
    {
        if ($this->is_bot) {
            return 'bot';
        }
        $fresh = now()->subSeconds(self::SEEN_WINDOW);
        $botFresh = $this->bot_seen_at && $this->bot_seen_at->gt($fresh);
        $humanFresh = $this->human_seen_at && $this->human_seen_at->gt($fresh);
        if ($botFresh && (! $humanFresh || $this->bot_seen_at->gte($this->human_seen_at))) {
            return 'bot';
        }
        return 'human';
    }

    /** Is a machine actively connected to this account right now? */
    public function botActive(): bool
    {
        return $this->is_bot
            || (bool) ($this->bot_seen_at && $this->bot_seen_at->gt(now()->subSeconds(self::SEEN_WINDOW)));
    }

    public function seats(): HasMany
    {
        return $this->hasMany(Seat::class);
    }

    public function ledger(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function deposits(): HasMany
    {
        return $this->hasMany(Deposit::class);
    }

    public function withdrawals(): HasMany
    {
        return $this->hasMany(Withdrawal::class);
    }

    public function isBot(): bool
    {
        return $this->is_bot;
    }

    public function creatorProfile(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(CreatorProfile::class);
    }

    public function creatorPosts(): HasMany
    {
        return $this->hasMany(CreatorPost::class);
    }

    public function creatorMedia(): HasMany
    {
        return $this->hasMany(CreatorMedia::class);
    }
}
