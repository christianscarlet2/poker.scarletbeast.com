<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Only the warden passes. */
class AdminOnly
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (!$user || !$user->is_admin) {
            return response()->json(['error' => 'The altar is sealed.'], 403);
        }
        return $next($request);
    }
}
