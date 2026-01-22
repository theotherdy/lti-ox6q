<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class LocalJwtAuth
{
    public function handle(Request $request, Closure $next)
    {
        $auth = $request->header('Authorization', '');
        if (!is_string($auth) || !preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) {
            return response()->json(['error' => 'Missing Bearer token.'], 401);
        }

        $token = $m[1];

        try {
            $payloadObj = JWT::decode($token, new Key((string) env('LOCAL_JWT_SECRET'), 'HS256'));
            $payload = json_decode(json_encode($payloadObj), true);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Invalid token.'], 401);
        }

        $sub = $payload['sub'] ?? null;
        if (!is_string($sub) || $sub === '') {
            return response()->json(['error' => 'Token missing sub claim.'], 401);
        }

        // Attach auth context to the request
        $request->attributes->set('auth', $payload);
        $request->attributes->set('auth_sub', $sub);

        return $next($request);
    }
}
