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

        // Validate LTI context is present
        $lti = $payload['lti'] ?? null;
        if (!is_array($lti)) {
            return response()->json(['error' => 'Token missing LTI context.'], 401);
        }

        $issuer = $lti['issuer'] ?? null;
        $deploymentId = $lti['deployment_id'] ?? null;
        $resourceLinkId = $lti['resource_link_id'] ?? null;

        if (!is_string($issuer) || $issuer === '' ||
            !is_string($deploymentId) || $deploymentId === '' ||
            !is_string($resourceLinkId) || $resourceLinkId === '') {
            return response()->json(['error' => 'Token missing required LTI context claims.'], 401);
        }

        // Attach auth context to the request
        $request->attributes->set('auth', $payload);
        $request->attributes->set('auth_sub', $sub);
        $request->attributes->set('auth_lti_issuer', $issuer);
        $request->attributes->set('auth_lti_deployment_id', $deploymentId);
        $request->attributes->set('auth_lti_resource_link_id', $resourceLinkId);

        return $next($request);
    }
}
