<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Firebase\JWT\JWT;
use App\Services\ToolSupportJwtVerifier;
use App\Services\LtiRoleResolver;

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

        // Validate LTI message type
        $messageType = $claims['https://purl.imsglobal.org/spec/lti/claim/message_type'] ?? null;
        if ($messageType !== 'LtiResourceLinkRequest' && $messageType !== 'LtiDeepLinkingRequest') {
            return response()->json([
                'error' => 'Invalid or unsupported LTI message type.'
            ], 400);
        }

        // Nonce validation (prevents replay attacks)
        $nonce = $claims['nonce'] ?? null;
        if (!is_string($nonce) || $nonce === '') {
            return response()->json(['error' => 'Missing nonce claim.'], 400);
        }

        $nonceKey = 'nonce:' . hash('sha256', $nonce);
        if (!Cache::add($nonceKey, 1, now()->addMinutes(10))) {
            return response()->json([
                'error' => 'Nonce has already been used (replay attack detected).'
            ], 409);
        }

        // Additional replay protection using jti/hash (defense in depth)
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

        // Pull useful context from launch claims.
        $roles = $claims['https://purl.imsglobal.org/spec/lti/claim/roles'] ?? null;
        $context = $claims['https://purl.imsglobal.org/spec/lti/claim/context'] ?? null;
        $custom = $claims['https://purl.imsglobal.org/spec/lti/claim/custom'] ?? null;
        $deepLinkingSettings = $claims['https://purl.imsglobal.org/spec/lti-dl/claim/deep_linking_settings'] ?? null;
        $launchPresentation = $claims['https://purl.imsglobal.org/spec/lti/claim/launch_presentation'] ?? null;
        $targetLinkUri = $claims['https://purl.imsglobal.org/spec/lti/claim/target_link_uri'] ?? null;

        $issuer = $claims['iss'] ?? null;
        $deploymentId = $claims['https://purl.imsglobal.org/spec/lti/claim/deployment_id'] ?? null;
        $resourceLink = $claims['https://purl.imsglobal.org/spec/lti/claim/resource_link'] ?? null;
        $resourceLinkId = is_array($resourceLink) ? ($resourceLink['id'] ?? null) : null;
        $contextId = is_array($context) ? ($context['id'] ?? null) : null;
        $launchMode = $messageType === 'LtiDeepLinkingRequest' ? 'deep_linking' : 'resource';

        $isInstructor = app(LtiRoleResolver::class)->isInstructor($roles);
        $custom = is_array($custom) ? $custom : null;
        $deepLinkingSettings = is_array($deepLinkingSettings) ? $deepLinkingSettings : null;
        $launchPresentation = is_array($launchPresentation) ? $launchPresentation : null;
        $targetLinkUri = is_string($targetLinkUri) ? $targetLinkUri : null;

        $now = time();
        $expiresIn = (int) env('LOCAL_JWT_EXPIRES_IN', 1800);

        $localPayload = [
            'iss' => config('app.url'),
            'sub' => $sub,
            'iat' => $now,
            'exp' => $now + $expiresIn,
            'roles' => $roles,
            'context' => $context,
            'launch_mode' => $launchMode,
            'lti' => [
                'issuer' => $issuer,
                'deployment_id' => $deploymentId,
                'resource_link_id' => $resourceLinkId,
                'context_id' => $contextId,
                'message_type' => $messageType,
                'deep_linking_settings' => $deepLinkingSettings,
                'launch_presentation' => $launchPresentation,
                'target_link_uri' => $targetLinkUri,
                'custom' => $custom,
                'is_instructor' => $isInstructor,
                'launch_mode' => $launchMode,
            ],
        ];

        $localJwt = JWT::encode($localPayload, env('LOCAL_JWT_SECRET'), 'HS256');

        $appId = $this->resolveCustomAppId($custom);

        if ($appId === null &&
            is_string($issuer) && $issuer !== '' &&
            is_string($deploymentId) && $deploymentId !== '' &&
            is_string($resourceLinkId) && $resourceLinkId !== '') {
            $row = DB::table('resource_links')
                ->where('issuer', $issuer)
                ->where('deployment_id', $deploymentId)
                ->where('resource_link_id', $resourceLinkId)
                ->first();
            if ($row) {
                $appId = (int) $row->app_id;
            }
        }

        return response()->json([
            'access_token' => $localJwt,
            'token_type' => 'Bearer',
            'expires_in' => $expiresIn,
            'subject' => $sub,
            'app_id' => $appId,
            'launch_mode' => $launchMode,
            'lti' => [
                'issuer' => $issuer,
                'deployment_id' => $deploymentId,
                'resource_link_id' => $resourceLinkId,
                'context_id' => $contextId,
                'message_type' => $messageType,
                'deep_linking_settings' => $deepLinkingSettings,
                'launch_presentation' => $launchPresentation,
                'target_link_uri' => $targetLinkUri,
                'custom' => $custom,
                'is_instructor' => $isInstructor,
                'launch_mode' => $launchMode,
            ],
        ]);
    }

    private function resolveCustomAppId(?array $custom): ?int
    {
        if (!$custom) {
            return null;
        }

        $candidate = $custom['ox6q_app_id'] ?? $custom['app_id'] ?? null;
        if (is_int($candidate)) {
            $appId = $candidate;
        } elseif (is_string($candidate) && ctype_digit($candidate)) {
            $appId = (int) $candidate;
        } else {
            return null;
        }

        if ($appId <= 0) {
            return null;
        }

        $exists = DB::table('apps')->where('id', $appId)->exists();
        return $exists ? $appId : null;
    }
}
