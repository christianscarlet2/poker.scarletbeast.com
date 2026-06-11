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

// Server-rendered API documentation (works without JS; full chrome + marquee).
Route::view('/api-docs', 'apidocs');
Route::view('/docs', 'apidocs');

// Native app downloads (desktop + mobile clients).
Route::view('/download', 'download');
Route::view('/downloads', 'download');

/* ----------------------------------------------------------------- auth */
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/2fa', [AuthController::class, 'twofa']);
Route::post('/auth/logout', [AuthController::class, 'logout']);

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
});
