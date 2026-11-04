<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-to-server auth for the QMS document hand-off.
 * The QMS sends the shared secret in the X-Integration-Key header (QMS_INTEGRATION_KEY in .env).
 */
class VerifyQmsIntegrationKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.qms.integration_key');
        $given = (string) $request->header('X-Integration-Key', '');

        if ($expected === '' || strlen($expected) < 32) {
            return response()->json(['message' => 'QMS integration is not configured.'], 503);
        }
        if (!hash_equals($expected, $given)) {
            return response()->json(['message' => 'Invalid integration key.'], 401);
        }

        return $next($request);
    }
}
