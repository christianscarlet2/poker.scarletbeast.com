<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\Stake; use App\Models\PokerTable; use App\Models\Seat; use App\Models\TableState; use Illuminate\Support\Facades\DB;
// Keep 2 machine_only NLHE felts per stake; close the rest to relieve MariaDB write load.
$closed = 0; $freed = 0;
foreach (Stake::where('enabled', true)->where('game_type', 'nlhe')->get() as $stake) {
    $keep = PokerTable::where('stake_id', $stake->id)->where('table_type', 'machine_only')
        ->where('status', 'active')->orderBy('id')->limit(2)->pluck('id')->all();
    $excess = PokerTable::where('stake_id', $stake->id)->where('table_type', 'machine_only')
        ->where('status', 'active')->whereNotIn('id', $keep)->get();
    foreach ($excess as $t) {
        $freed += Seat::where('table_id', $t->id)->where('status', '!=', 'empty')->count();
        Seat::where('table_id', $t->id)->delete();
        TableState::where('table_id', $t->id)->update(['state' => null, 'phase' => 'idle', 'version' => DB::raw('version+1')]);
        $t->status = 'closed'; $t->save(); $closed++;
    }
}
echo "closed $closed excess machine_only felts (freed $freed seats)\n";
echo "active NLHE felts now: " . PokerTable::where('status','active')->where('game_type','nlhe')->where('hand_no','>',0)->count() . " (+ open spares)\n";
