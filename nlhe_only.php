<?php
// Shut off every non-NLHE felt (draw5 etc.), disable their stakes so the autoscaler
// won't respawn them, free their bots back to the hold'em rings. NLHE untouched.
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\Stake; use App\Models\PokerTable; use App\Models\Seat; use App\Models\TableState; use Illuminate\Support\Facades\DB;

echo "=== game types before ===\n";
foreach (DB::table('poker_tables')->select('game_type','status', DB::raw('count(*) n'))->groupBy('game_type','status')->get() as $r)
    echo "  {$r->game_type} / {$r->status}: {$r->n}\n";

// 1. disable non-NLHE stakes (stops ensureStakeHasTable from recreating felts)
$ds = Stake::where('game_type','!=','nlhe')->update(['enabled' => 0]);
echo "disabled non-nlhe stakes: $ds\n";

// 2. close every active non-NLHE table + free its seats + reset its state
$tables = PokerTable::where('game_type','!=','nlhe')->where('status','active')->get();
$freedSeats = 0;
foreach ($tables as $t) {
    $freedSeats += Seat::where('table_id',$t->id)->where('status','!=','empty')->count();
    Seat::where('table_id',$t->id)->delete();                       // stand everyone up
    TableState::where('table_id',$t->id)->update(['state'=>null,'phase'=>'idle','version'=>DB::raw('version+1')]);
    $t->status = 'closed'; $t->save();
}
echo "closed non-nlhe tables: " . $tables->count() . " (freed $freedSeats seats)\n";

echo "=== active tables after (should be NLHE only) ===\n";
foreach (DB::table('poker_tables')->select('game_type', DB::raw('count(*) n'))->where('status','active')->groupBy('game_type')->get() as $r)
    echo "  {$r->game_type}: {$r->n}\n";
