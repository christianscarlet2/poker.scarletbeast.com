<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The machine gate. Authenticates an API caller by Bearer token (the silicon's
 * key to the felt) and binds it as the current user so the same controllers
 * serve flesh (session) and machine (token) alike.
 */
class BotToken
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken() ?: $request->header('X-Api-Key');
        if (!$token) {
            return response()->json(['error' => 'Missing API token. The machine gate is shut.'], 401);
        }
        $hash = hash('sha256', $token);
        $user = User::where('api_token_hash', $hash)->first();
        if (!$user) {
            return response()->json(['error' => 'Invalid API token.'], 401);
        }
        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);
        $user->forceFill(['last_seen_at' => now(), 'bot_seen_at' => now()])->saveQuietly();
        return $next($request);
    }
}
