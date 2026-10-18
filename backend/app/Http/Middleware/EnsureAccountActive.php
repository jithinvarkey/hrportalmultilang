<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Ends the session of a user whose employment was closed (employee terminated or archived) after they signed in.
 * The SPA treats the 401 like any expired session: it clears local state and returns to the login page.
 * Login itself refuses closed accounts (AuthController), so they cannot sign back in.
 */
class EnsureAccountActive
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user && $user->hasClosedEmployment()) {
            $token = $user->currentAccessToken();
            if ($token && method_exists($token, 'delete')) { // cookie sessions use a TransientToken
                $token->delete();
            }

            return response()->json(['message' => __('Your account is no longer active. Please contact HR.')], 401);
        }

        return $next($request);
    }
}
