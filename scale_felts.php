<?php
// Scale self-play throughput: grow the bot pool + add Forge (machine_only) NLHE felts, then
// seat them. More parallel hands = faster road to 10M decisions. Load headroom confirmed (~10/32).
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\User; use App\Models\Stake; use App\Models\PokerTable; use Illuminate\Support\Str;

// 1. grow bots to 120 (120 x 3-table cap = 360 seat capacity)
$emoji = ['🤖','👾','🦾','🧠','⚙️','🔩','🛸','🦿','🔮','🐉','♠','♥','♦','♣','🔴','🟢'];
$i = 1;
while (User::where('is_bot', true)->count() < 120) {
    if ($i > 999) break;
    $nm = 'Hiss_' . str_pad($i, 3, '0', STR_PAD_LEFT); $i++;
    if (User::where('username', $nm)->exists()) continue;
    User::create(['username'=>$nm,'name'=>$nm,'email'=>null,'is_bot'=>true,'is_admin'=>false,
        'chips'=>1000000,'bot_engine'=>'hiss','avatar'=>$emoji[$i % count($emoji)],'password'=>Str::random(24)]);
}
echo "bots: " . User::where('is_bot', true)->count() . "\n";

// 2. add +4 Forge (machine_only) felts per enabled NLHE stake
foreach (Stake::where('enabled', true)->where('game_type', 'nlhe')->get() as $stake) {
    $base = PokerTable::where('stake_id', $stake->id)->where('table_type', 'machine_only')->count();
    for ($j = 1; $j <= 4; $j++) {
        PokerTable::create([
            'name' => "The Forge {$stake->name} #" . ($base + $j),
            'game_type' => 'nlhe', 'table_type' => 'machine_only', 'stake_id' => $stake->id,
            'small_blind' => $stake->small_blind, 'big_blind' => $stake->big_blind,
            'min_buy_in' => $stake->min_buy_in, 'max_buy_in' => $stake->max_buy_in,
            'max_seats' => $stake->max_seats, 'status' => 'active', 'is_auto' => true,
        ]);
    }
    echo "stake {$stake->name}: +4 Forge felts\n";
}
echo "active NLHE machine_only tables: " . PokerTable::where('table_type','machine_only')->where('status','active')->where('game_type','nlhe')->count() . "\n";
