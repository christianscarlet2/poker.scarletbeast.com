<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\User;
// Convert 40 bots to the NN champion engine for a live NN-vs-OHF showdown. 🧠 avatar so they're
// visible at the felt. The rest stay on the OHF strategy (and remain the training-data source).
$ids = User::where('is_bot', true)->where('bot_engine', 'hiss')->orderBy('id', 'desc')->limit(40)->pluck('id');
User::whereIn('id', $ids)->update(['bot_engine' => 'hiss-nn', 'avatar' => '🧠']);
echo "NN bots (hiss-nn): " . User::where('bot_engine', 'hiss-nn')->count() . "\n";
echo "OHF bots (hiss):   " . User::where('bot_engine', 'hiss')->count() . "\n";
