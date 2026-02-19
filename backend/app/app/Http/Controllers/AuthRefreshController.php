<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;

class AuthRefreshController extends Controller
{
    public function refresh(Request $request)
    {
        $auth = $request->header('Authorization', '');
        if (!is_string($auth) || !preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) {
            return response()->json(['error' => 'Missing Bearer token.'], 401);
        }

        $token = $m[1];
        $secret = (string) env('LOCAL_JWT_SECRET');

        try {
            // Try to decode normally first
            $payloadObj = JWT::decode($token, new Key($secret, 'HS256'));
            $payload = json_decode(json_encode($payloadObj), true);
        } catch (ExpiredException $e) {
            // Token expired - decode without verification to get claims
            try {
                $parts = explode('.', $token);
                if (count($parts) !== 3) {
                    return response()->json(['error' => 'Invalid token format.'], 401);
                }

                // Verify signature manually
                $headerB64 = $parts[0];
                $payloadB64 = $parts[1];
                $signatureProvided = $parts[2];

                $signature = JWT::urlsafeB64Decode($signatureProvided);
                $valid = JWT::sign($headerB64 . '.' . $payloadB64, $secret, 'HS256');

                if (!hash_equals($signature, $valid)) {
                    return response()->json(['error' => 'Invalid token signature.'], 401);
                }

                // Decode payload
                $payloadJson = JWT::urlsafeB64Decode($payloadB64);
                $payload = json_decode($payloadJson, true);

                if (!is_array($payload)) {
                    return response()->json(['error' => 'Invalid token payload.'], 401);
                }
            } catch (\Throwable $e) {
                return response()->json(['error' => 'Invalid or corrupted token.'], 401);
            }
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Invalid token.'], 401);
        }

        // Validate required claims
        $sub = $payload['sub'] ?? null;
        if (!is_string($sub) || $sub === '') {
            return response()->json(['error' => 'Token missing sub claim.'], 401);
        }

        // Issue new token with same claims but updated timestamps
        $now = time();
        $expiresIn = (int) env('LOCAL_JWT_EXPIRES_IN', 1800);

        $newPayload = [
            'iss' => config('app.url'),
            'sub' => $sub,
            'iat' => $now,
            'exp' => $now + $expiresIn,
            'roles' => $payload['roles'] ?? null,
            'context' => $payload['context'] ?? null,
            'launch_mode' => $payload['launch_mode'] ?? null,
            'lti' => $payload['lti'] ?? null,
        ];

        $newToken = JWT::encode($newPayload, $secret, 'HS256');

        return response()->json([
            'access_token' => $newToken,
            'token_type' => 'Bearer',
            'expires_in' => $expiresIn,
        ]);
    }
}
