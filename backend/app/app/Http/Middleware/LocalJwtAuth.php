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
            // Try to decode without verification to see timing info
            $parts = explode('.', $token);
            if (count($parts) === 3) {
                try {
                    $payloadJson = base64_decode(strtr($parts[1], '-_', '+/'));
                    $decoded = json_decode($payloadJson, true);
                    \Log::warning('LocalJwtAuth: Token decode failed', [
                        'error' => $e->getMessage(),
                        'error_class' => get_class($e),
                        'token_iat' => $decoded['iat'] ?? null,
                        'token_exp' => $decoded['exp'] ?? null,
                        'now' => time(),
                        'age_seconds' => time() - ($decoded['iat'] ?? time()),
                        'expired_by_seconds' => time() - ($decoded['exp'] ?? time()),
                    ]);
                } catch (\Throwable $ignored) {
                    \Log::warning('LocalJwtAuth: Token decode failed', [
                        'error' => $e->getMessage(),
                        'error_class' => get_class($e),
                    ]);
                }
            }
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
        $messageType = $lti['message_type'] ?? null;
        $launchMode = $payload['launch_mode'] ?? ($lti['launch_mode'] ?? 'resource');
        if (!is_string($launchMode) || $launchMode === '') {
            $launchMode = 'resource';
        }
        $isInstructor = (bool) ($lti['is_instructor'] ?? false);

        if (!is_string($issuer) || $issuer === '' ||
            !is_string($deploymentId) || $deploymentId === '') {
            return response()->json(['error' => 'Token missing required LTI issuer/deployment claims.'], 401);
        }

        $isDeepLinkLaunch = ($launchMode === 'deep_linking') || ($messageType === 'LtiDeepLinkingRequest');
        if (!$isDeepLinkLaunch && (!is_string($resourceLinkId) || $resourceLinkId === '')) {
            return response()->json(['error' => 'Token missing required LTI resource_link_id claim.'], 401);
        }

        // Attach auth context to the request
        $request->attributes->set('auth', $payload);
        $request->attributes->set('auth_sub', $sub);
        $request->attributes->set('auth_lti_issuer', $issuer);
        $request->attributes->set('auth_lti_deployment_id', $deploymentId);
        $request->attributes->set('auth_lti_resource_link_id', $resourceLinkId);
        $request->attributes->set('auth_lti_message_type', $messageType);
        $request->attributes->set('auth_launch_mode', $launchMode);
        $request->attributes->set('auth_lti_is_instructor', $isInstructor);

        return $next($request);
    }
}
