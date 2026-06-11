<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Models\Stake;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class PokerSeeder extends Seeder
{
    public function run(): void
    {
        // Standard no-limit hold'em blind ladder, denominated in chips
        // (1 chip = $0.01, so 50 chips = $0.50). 40bb–100bb buy-ins.
        $stakes = [
            ['name' => '25/50',     'sb' => 25,  'bb' => 50,   'min' => 2000,  'max' => 5000,   'sort' => 1],
            ['name' => '100/200',   'sb' => 100, 'bb' => 200,  'min' => 8000,  'max' => 20000,  'sort' => 2],
            ['name' => '200/500',   'sb' => 200, 'bb' => 500,  'min' => 20000, 'max' => 50000,  'sort' => 3],
            ['name' => '500/1000',  'sb' => 500, 'bb' => 1000, 'min' => 40000, 'max' => 100000, 'sort' => 4],
        ];
        foreach ($stakes as $s) {
            Stake::updateOrCreate(
                ['name' => $s['name']],
                [
                    'small_blind' => $s['sb'], 'big_blind' => $s['bb'],
                    'min_buy_in' => $s['min'], 'max_buy_in' => $s['max'],
                    'max_seats' => 6, 'sort' => $s['sort'], 'enabled' => true,
                ]
            );
        }

        // The admin — keeper of the altar. Password overridable via env at seed.
        $adminPass = env('ADMIN_PASSWORD', 'beast-' . substr(bin2hex(random_bytes(6)), 0, 10));
        $admin = User::updateOrCreate(
            ['username' => 'warden'],
            [
                'name' => 'The Warden',
                'email' => 'scarlet@scarletbeast.com',
                'password' => Hash::make($adminPass),
                'is_admin' => true,
                'is_bot' => false,
                'chips' => 0,
                'avatar' => '⛧',
            ]
        );
        $this->command?->warn("ADMIN  username: warden   password: {$adminPass}");

        // Seed default house settings.
        foreach (Setting::DEFAULTS as $k => $v) {
            Setting::firstOrCreate(['key' => $k], ['value' => $v]);
        }
    }
}
