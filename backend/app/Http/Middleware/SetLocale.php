<?php

namespace App\Http\Middleware;

use App\Support\Locale\SupportedLocales;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * API responses (validation errors, messages) follow the language the SPA sends in Accept-Language.
 * Falls back to the signed-in user's saved preference, then English.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        $header = $request->header('X-Locale') ?: $request->header('Accept-Language');
        $requested = $header ? explode(',', $header)[0] : null;

        if (SupportedLocales::isSupported($requested)) {
            $locale = SupportedLocales::normalize($requested);
        } else {
            $user = $request->user('sanctum');
            $locale = SupportedLocales::normalize($user->preferred_language ?? null);
        }

        App::setLocale($locale);

        $response = $next($request);
        $response->headers->set('Content-Language', $locale);

        return $response;
    }
}
