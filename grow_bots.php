<?php
// Relieve bot-pool starvation so busted bots re-seat on EVERY felt (incl. draw5).
// 48 bots x 3-felt cap = 144 seats < ~151 NLHE wanted -> draw felts starve. Grow pool.
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\User; use App\Models\PokerTable; use App\Models\Seat; use Illuminate\Support\Str;

$emoji = ['🤖','👾','🦾','🧠','⚙️','🔩','🛸','🦿','🔮','🐉','♠','♥','♦','♣'];
$target = (int)(getenv('FLEET_TARGET') ?: 72);   // 72 x 3 = 216 seat capacity vs ~96 wanted
$made = 0; $i = 1;
while (User::where('is_bot', true)->count() < $target) {
    if ($i > 999) break;
    $nm = 'Hiss_' . str_pad($i, 2, '0', STR_PAD_LEFT); $i++;
    if (User::where('username', $nm)->exists()) continue;
    User::create([
        'username' => $nm, 'name' => $nm, 'email' => null,
        'is_bot' => true, 'is_admin' => false, 'chips' => 1000000,
        'bot_engine' => 'hiss', 'avatar' => $emoji[$i % count($emoji)],
        'password' => Str::random(24),
    ]); $made++;
}
echo "bots now: " . User::where('is_bot', true)->count() . " (added $made)\n";
// draw5 occupancy snapshot
foreach (PokerTable::where('game_type','draw5')->where('status','active')->get() as $t) {
    $occ = Seat::where('table_id',$t->id)->where('status','!=','empty')->count();
    echo sprintf("  draw5 #%d %-28s seats=%d/%d hand=%d\n", $t->id, $t->name, $occ, $t->max_seats, $t->hand_no);
}
