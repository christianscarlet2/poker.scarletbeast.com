<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\User;
// 40 OHF bots -> diversity archetypes (10 each). Keep the rest pure 'hiss' (the emitters).
$styles = [['maniac','🔥'], ['station','🐟'], ['nit','🪨'], ['lag','😈']];
$ids = User::where('is_bot',true)->where('bot_engine','hiss')->orderBy('id')->limit(40)->pluck('id')->all();
foreach (array_chunk($ids, 10) as $i => $chunk) {
    [$style,$av] = $styles[$i];
    User::whereIn('id',$chunk)->update(['bot_engine'=>"hiss-style:$style", 'avatar'=>$av]);
}
foreach (['hiss','hiss-style:maniac','hiss-style:station','hiss-style:nit','hiss-style:lag','hiss-nn'] as $e)
    echo str_pad($e,20)." ".User::where('bot_engine',$e)->count()."\n";
