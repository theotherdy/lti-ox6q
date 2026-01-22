<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Firebase\JWT\JWT;
use App\Services\ToolSupportJwtVerifier;

class AuthBootstrapController extends Controller
{
    public function bootstrap(Request $request)
    {
        $request->validate([
            'tool_support_jwt' => ['required', 'string'],
        ]);

        $jwt = $request->input('tool_support_jwt');

        $verifier = app(ToolSupportJwtVerifier::class);
        $claims = $verifier->verify($jwt);

        // Replay protection (bootstrap is single-use)
        $replayKey = 'bootstrap:';
        if (isset($claims['jti']) && is_string($claims['jti']) && $claims['jti'] !== '') {
            $replayKey .= 'jti:' . $claims['jti'];
        } else {
            $replayKey .= 'hash:' . hash('sha256', $jwt);
        }

        $ok = Cache::add($replayKey, 1, now()->addMinutes(10));
        if (!$ok) {
            return response()->json([
                'error' => 'This launch token has already been used for bootstrap.'
            ], 409);
        }

        $sub = $claims['sub'] ?? null;
        if (!is_string($sub) || $sub === '') {
            return response()->json(['error' => 'Missing sub claim in Tool Support JWT.'], 400);
        }

        // Pull a few useful bits if present (optional)
        $roles = $claims['https://purl.imsglobal.org/spec/lti/claim/roles'] ?? null;
        $context = $claims['https://purl.imsglobal.org/spec/lti/claim/context'] ?? null;

        $now = time();
        $expiresIn = 30 * 60;

        $localPayload = [
            'iss' => config('app.url'),
            'sub' => $sub,
            'iat' => $now,
            'exp' => $now + $expiresIn,
            'roles' => $roles,
            'context' => $context,
        ];

        $localJwt = JWT::encode($localPayload, env('LOCAL_JWT_SECRET'), 'HS256');

        return response()->json([
            'access_token' => $localJwt,
            'token_type' => 'Bearer',
            'expires_in' => $expiresIn,
            'subject' => $sub,
        ]);
    }
}
