<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\Stake; use App\Models\PokerTable; use App\Models\Seat; use App\Models\TableState; use Illuminate\Support\Facades\DB;
$keep = ['25/50', 'NLHE 25/50 8-max', 'NLHE 25/50 9-max'];   // standard 6/8/9-max only
// disable the extra high-stakes NLHE stakes
Stake::where('game_type','nlhe')->whereNotIn('name',$keep)->update(['enabled'=>0]);
$disabledIds = Stake::where('game_type','nlhe')->whereNotIn('name',$keep)->pluck('id')->all();
$closed=0;$freed=0;
$closeTable=function($t) use (&$closed,&$freed){
    $freed += Seat::where('table_id',$t->id)->where('status','!=','empty')->count();
    Seat::where('table_id',$t->id)->delete();
    TableState::where('table_id',$t->id)->update(['state'=>null,'phase'=>'idle','version'=>DB::raw('version+1')]);
    $t->status='closed';$t->save();$closed++;
};
// close ALL tables on disabled stakes
foreach (PokerTable::whereIn('stake_id',$disabledIds)->where('status','active')->get() as $t) $closeTable($t);
// on kept stakes, keep 2 machine_only each, close the rest
foreach (Stake::whereIn('name',$keep)->get() as $stake) {
    $k = PokerTable::where('stake_id',$stake->id)->where('table_type','machine_only')->where('status','active')->orderBy('id')->limit(2)->pluck('id')->all();
    foreach (PokerTable::where('stake_id',$stake->id)->where('table_type','machine_only')->where('status','active')->whereNotIn('id',$k)->get() as $t) $closeTable($t);
}
echo "closed $closed felts, freed $freed seats\n";
echo "active NLHE felts: ".PokerTable::where('status','active')->where('game_type','nlhe')->count()."\n";
