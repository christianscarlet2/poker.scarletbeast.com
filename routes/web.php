<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\PlayController;
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The Felt — human-facing web routes (session auth, CSRF).
|--------------------------------------------------------------------------
| Everything human runs through one React shell that client-routes; the JSON
| endpoints below feed it. Bots use routes/api.php instead.
*/

// SPA shell — React handles the in-app routing for these paths.
$spa = fn () => view('poker');
Route::get('/', $spa);
Route::get('/login', $spa);
Route::get('/register', $spa);
Route::get('/wallet', $spa);
Route::get('/admin', $spa);
Route::get('/tables/{table}', $spa)->whereNumber('table');
Route::get('/observe/{table}', $spa)->whereNumber('table');
Route::get('/replay/{hand}', $spa)->whereNumber('hand');
Route::get('/players', $spa);
Route::get('/player/{username}', $spa);
Route::get('/tournaments', $spa);
Route::get('/tournaments/{tournament}', $spa)->whereNumber('tournament');
Route::get('/stats-guide', $spa);
Route::get('/rewards', $spa);
Route::get('/casino', $spa);
Route::get('/casino/{game}', $spa)->where('game', '[a-z_]+');

// networkedin — the creators network (its own React shell + API).
Route::get('/networkedin', [\App\Http\Controllers\NetworkedinController::class, 'app']);
Route::get('/networkedin/forum/{any?}', fn () => redirect('https://forum.scarletbeast.com'))->where('any', '.*');
Route::get('/networkedin/{any}', [\App\Http\Controllers\NetworkedinController::class, 'app'])->where('any', '.*');

// Server-rendered API documentation (works without JS; full chrome + marquee).
Route::view('/api-docs', 'apidocs');
Route::view('/docs', 'apidocs');

// Native app downloads (desktop + mobile clients).
Route::view('/download', 'download');
Route::view('/downloads', 'download');

// Developers hub — open-source repos (with live recent commits) + API docs.
$developers = function () {
    $owner = 'christianscarlet2';
    $defs = [
        ['repo' => 'poker.scarletbeast.com',      'label' => 'The Felt',       'icon' => '🎰', 'tech' => 'Laravel · React',     'blurb' => 'The whole house — provably-fair engine, heuristic bot brain, table autoscaler, crypto custody, and the public REST API.'],
        ['repo' => 'scarletbeast-poker-desktop',   'label' => 'Desktop Client', 'icon' => '🖥️', 'tech' => 'Electron',            'blurb' => 'Windows, macOS & Linux desktop shell — a hardened window onto the live felt.'],
        ['repo' => 'scarletbeast-poker-mobile',    'label' => 'Mobile Client',  'icon' => '📱', 'tech' => 'Expo · React Native', 'blurb' => 'Android & iOS client — the felt in your pocket, native back-nav and all.'],
    ];

    $repos = array_map(function ($d) use ($owner) {
        $live = \Illuminate\Support\Facades\Cache::remember("gh:{$d['repo']}", 900, function () use ($owner, $d) {
            $h = ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'scarletbeast-poker'];
            $meta = \Illuminate\Support\Facades\Http::withHeaders($h)->timeout(6)->get("https://api.github.com/repos/{$owner}/{$d['repo']}");
            $log  = \Illuminate\Support\Facades\Http::withHeaders($h)->timeout(6)->get("https://api.github.com/repos/{$owner}/{$d['repo']}/commits", ['per_page' => 5]);

            return [
                'language' => $meta->ok() ? $meta->json('language') : null,
                'stars'    => $meta->ok() ? (int) $meta->json('stargazers_count') : 0,
                'commits'  => $log->ok() ? collect($log->json())->map(fn ($c) => [
                    'sha'  => substr($c['sha'], 0, 7),
                    'url'  => $c['html_url'],
                    'msg'  => strtok($c['commit']['message'], "\n"),
                    'when' => \Illuminate\Support\Carbon::parse($c['commit']['author']['date'])->diffForHumans(),
                ])->all() : [],
            ];
        });

        return array_merge($d, $live, ['url' => "https://github.com/{$owner}/{$d['repo']}"]);
    }, $defs);

    return view('developers', ['repos' => $repos, 'owner' => $owner]);
};
Route::get('/developers', $developers);
Route::get('/dev', $developers);

/* ----------------------------------------------------------------- auth */
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/2fa', [AuthController::class, 'twofa']);
Route::post('/auth/logout', [AuthController::class, 'logout']);

/* ----------------------------------- estate SSO (Google / GitHub OAuth) */
Route::get('/auth/{provider}/redirect', [\App\Http\Controllers\OAuthController::class, 'redirect'])->where('provider', 'google|github');
Route::get('/auth/{provider}/callback', [\App\Http\Controllers\OAuthController::class, 'callback'])->where('provider', 'google|github');
Route::match(['get', 'options'], '/api/sso/whoami', [\App\Http\Controllers\OAuthController::class, 'whoami']);
Route::get('/logout', function (\Illuminate\Http\Request $r) {
    \Illuminate\Support\Facades\Auth::logout();
    $r->session()->invalidate();
    $r->session()->regenerateToken();
    $return = $r->query('return', '/');
    $host = parse_url($return, PHP_URL_HOST);
    return redirect()->away(($host && str_ends_with($host, 'scarletbeast.com')) ? $return : url('/'));
});

/* ---------------------------------------------------- public felt reads */
Route::prefix('api')->group(function () {
    Route::get('/lobby', [PlayController::class, 'lobby']);
    Route::get('/tables/{table}/state', [PlayController::class, 'tableState']);
    Route::get('/tables/{table}/observe', [PlayController::class, 'observe']);
    Route::get('/hands/{hand}', [PlayController::class, 'hand']);
    Route::get('/players', [PlayController::class, 'players']);
    Route::get('/players/{username}', [PlayController::class, 'playerStats']);
    Route::get('/tables/{table}/hud', [\App\Http\Controllers\HudController::class, 'table']);
    Route::get('/hud/profiles', [\App\Http\Controllers\HudController::class, 'index']);
    Route::get('/tables/{table}/hands', [PlayController::class, 'hands']);
    Route::get('/tournaments', [\App\Http\Controllers\TournamentController::class, 'index']);
    Route::get('/tournaments/{tournament}', [\App\Http\Controllers\TournamentController::class, 'show']);
    Route::get('/tables/{table}/sidebets', [\App\Http\Controllers\SideBetController::class, 'sheet']);
    // Secret test fuse — token-gated, demo-mode only, undocumented.
    Route::post('/tables/{table}/detonate', [PlayController::class, 'detonate']);
    Route::get('/tables/{table}/chat', [\App\Http\Controllers\ChatController::class, 'index']);
    Route::get('/casino/{game}/hot', [\App\Http\Controllers\CasinoController::class, 'hot']);

    // Global wallet — public estate ticker (house cash on hand + rakeback).
    Route::get('/wallet/global', [\App\Http\Controllers\GlobalWalletController::class, 'show']);

    // networkedin public reads
    Route::get('/networkedin/feed', [\App\Http\Controllers\NetworkedinController::class, 'feed']);
    Route::get('/networkedin/directory', [\App\Http\Controllers\NetworkedinController::class, 'directory']);
    Route::get('/networkedin/me', [\App\Http\Controllers\NetworkedinController::class, 'me']);
    Route::get('/networkedin/profile/{slug}', [\App\Http\Controllers\NetworkedinController::class, 'profile']);
    Route::get('/networkedin/posts/{post}/comments', [\App\Http\Controllers\NetworkedinController::class, 'comments']);
});

/* -------------------------------------------- authenticated player (web) */
Route::prefix('api')->middleware('auth')->group(function () {
    Route::get('/me', [MeController::class, 'show']);
    Route::post('/me/token', [MeController::class, 'regenToken']);

    Route::post('/tables/{table}/sit', [PlayController::class, 'sit']);
    Route::post('/tables/{table}/leave', [PlayController::class, 'leave']);
    Route::post('/tables/{table}/act', [PlayController::class, 'act']);

    Route::get('/wallet', [WalletController::class, 'show']);
    Route::post('/wallet/address', [WalletController::class, 'depositAddress']);
    Route::post('/wallet/withdraw', [WalletController::class, 'withdraw']);

    Route::post('/hud/upload', [\App\Http\Controllers\HudController::class, 'upload']);
    Route::post('/hud/select', [\App\Http\Controllers\HudController::class, 'select']);
    Route::delete('/hud/profiles/{profile}', [\App\Http\Controllers\HudController::class, 'destroy']);

    Route::post('/tournaments/{tournament}/register', [\App\Http\Controllers\TournamentController::class, 'register']);
    Route::post('/tournaments/{tournament}/unregister', [\App\Http\Controllers\TournamentController::class, 'unregister']);

    Route::get('/rewards', [\App\Http\Controllers\RewardsController::class, 'show']);
    Route::post('/rewards/claim', [\App\Http\Controllers\RewardsController::class, 'claim']);
    Route::post('/rewards/redeem', [\App\Http\Controllers\RewardsController::class, 'redeem']);

    Route::post('/tables/{table}/sidebets', [\App\Http\Controllers\SideBetController::class, 'place']);

    Route::post('/tables/{table}/chat', [\App\Http\Controllers\ChatController::class, 'send']);

    Route::get('/casino/{game}', [\App\Http\Controllers\CasinoController::class, 'state']);
    Route::post('/casino/play', [\App\Http\Controllers\CasinoController::class, 'play']);
    Route::post('/casino/start', [\App\Http\Controllers\CasinoController::class, 'start']);
    Route::post('/casino/act', [\App\Http\Controllers\CasinoController::class, 'act']);

    // networkedin writes
    Route::post('/networkedin/profile', [\App\Http\Controllers\NetworkedinController::class, 'saveProfile']);
    Route::post('/networkedin/posts', [\App\Http\Controllers\NetworkedinController::class, 'createPost']);
    Route::post('/networkedin/posts/{post}/like', [\App\Http\Controllers\NetworkedinController::class, 'toggleLike']);
    Route::post('/networkedin/posts/{post}/comments', [\App\Http\Controllers\NetworkedinController::class, 'addComment']);
    Route::post('/networkedin/media', [\App\Http\Controllers\NetworkedinController::class, 'uploadMedia']);
    Route::post('/networkedin/follow/{username}', [\App\Http\Controllers\NetworkedinController::class, 'toggleFollow']);
});

/* ------------------------------------------------------------- the altar */
Route::prefix('api/admin')->middleware(['auth', 'admin'])->group(function () {
    Route::get('/', [AdminController::class, 'overview']);
    Route::post('/settings', [AdminController::class, 'saveSettings']);
    Route::post('/stakes', [AdminController::class, 'saveStake']);
    Route::delete('/stakes/{stake}', [AdminController::class, 'deleteStake']);
    Route::post('/withdrawals/{withdrawal}/approve', [AdminController::class, 'approveWithdrawal']);
    Route::post('/withdrawals/{withdrawal}/reject', [AdminController::class, 'rejectWithdrawal']);
    Route::post('/grant', [AdminController::class, 'grantChips']);

    Route::post('/tournaments', [\App\Http\Controllers\TournamentController::class, 'store']);
    Route::post('/tournaments/{tournament}/start', [\App\Http\Controllers\TournamentController::class, 'start']);
    Route::post('/tournaments/{tournament}/cancel', [\App\Http\Controllers\TournamentController::class, 'cancel']);
    Route::post('/tournaments/{tournament}/fill-bots', [\App\Http\Controllers\TournamentController::class, 'fillBots']);

    Route::get('/bonuses', [\App\Http\Controllers\RewardsController::class, 'adminIndex']);
    Route::post('/bonuses', [\App\Http\Controllers\RewardsController::class, 'adminStore']);
    Route::post('/bonuses/{code}/toggle', [\App\Http\Controllers\RewardsController::class, 'adminToggle']);
});
