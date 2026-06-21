<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\Stake; use App\Models\PokerTable;
// Add Forge (machine_only) felts back toward ~24 total, moderate (avoids the 60-felt overload).
foreach (Stake::where('enabled', true)->where('game_type', 'nlhe')->get() as $stake) {
    $have = PokerTable::where('stake_id', $stake->id)->where('table_type', 'machine_only')->where('status', 'active')->count();
    $base = PokerTable::where('stake_id', $stake->id)->where('table_type', 'machine_only')->count();
    for ($j = 1; $have + $j - 1 < 6; $j++) {  // bring each stake to ~6 machine_only felts
        PokerTable::create(['name'=>"The Forge {$stake->name} #" . ($base + $j),'game_type'=>'nlhe',
            'table_type'=>'machine_only','stake_id'=>$stake->id,'small_blind'=>$stake->small_blind,
            'big_blind'=>$stake->big_blind,'min_buy_in'=>$stake->min_buy_in,'max_buy_in'=>$stake->max_buy_in,
            'max_seats'=>$stake->max_seats,'status'=>'active','is_auto'=>true]);
    }
}
echo "active NLHE felts: " . PokerTable::where('status','active')->where('game_type','nlhe')->count() . "\n";
