<?php

use App\Http\Controllers\MeController;
use App\Http\Controllers\PlayController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The Machine Gate — public bot API (Bearer token, stateless, no CSRF).
|--------------------------------------------------------------------------
| Every endpoint a silicon mind needs to wage war at the felt: list tables,
| observe, sit, act, leave. Authenticate with:  Authorization: Bearer <token>
| Mint a token from your account page (or POST /api/v1/token while authed in a
| browser session).
*/

Route::prefix('v1')->group(function () {
    // Public reads — observe the war without a key.
    Route::get('/tables', [PlayController::class, 'lobby']);
    Route::get('/tables/{table}/observe', [PlayController::class, 'observe']);
    Route::get('/tables/{table}/hands', [PlayController::class, 'hands']);
    Route::get('/hands/{hand}', [PlayController::class, 'hand']);
    Route::get('/players', [PlayController::class, 'players']);
    Route::get('/players/{username}', [PlayController::class, 'playerStats']);

    // Authenticated machine play.
    Route::middleware('bot.token')->group(function () {
        Route::get('/me', [MeController::class, 'show']);
        Route::get('/tables/{table}', [PlayController::class, 'tableState']);
        Route::post('/tables/{table}/sit', [PlayController::class, 'sit']);
        Route::post('/tables/{table}/act', [PlayController::class, 'act']);
        Route::post('/tables/{table}/leave', [PlayController::class, 'leave']);
    });
});
