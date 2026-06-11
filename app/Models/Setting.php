<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];

    protected $casts = [
        'value' => 'array',
    ];

    /** Default house settings — the altar's opening configuration. */
    public const DEFAULTS = [
        'cpu_count' => 4,
        'workers_per_cpu' => 2,
        'action_timeout' => 25,        // seconds a player has to act
        'rake_bps' => 500,             // basis points of pot taken as rake (500 = 5%, industry standard)
        'rake_cap_bb' => 3,            // rake capped at N big blinds (no flop, no drop)
        'bot_think_min' => 800,        // ms
        'bot_think_max' => 2600,       // ms
        'min_bots_per_table' => 2,     // keep machine tables alive with this many bots
        'crypto_network' => 'test',
        'btc_main_wallet' => '',       // house cold address (display only)
        'eth_main_wallet' => '',
        'withdraw_auto_approve_under_usd' => 0, // 0 = always manual approve
    ];

    public static function get(string $key, $default = null)
    {
        $cached = Cache::remember("setting:$key", 30, function () use ($key) {
            $row = static::find($key);
            return $row ? ['v' => $row->value] : null;
        });
        if ($cached !== null) {
            return $cached['v'];
        }
        return $default ?? (self::DEFAULTS[$key] ?? null);
    }

    public static function put(string $key, $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget("setting:$key");
    }

    public static function all_settings(): array
    {
        $out = self::DEFAULTS;
        foreach (static::all() as $row) {
            $out[$row->key] = $row->value;
        }
        return $out;
    }
}
