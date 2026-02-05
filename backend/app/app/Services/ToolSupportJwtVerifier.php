<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Firebase\JWT\JWT;
use Firebase\JWT\JWK;

class ToolSupportJwtVerifier
{
    /**
     * Verifies the Tool Support JWT.
     *
     * By default it will:
     * - verify signature using a JWKS (if TOOLSUPPORT_JWKS_URL is set)
     * - verify iss/aud if provided
     * - verify exp (always)
     *
     * If TOOLSUPPORT_SKIP_SIGNATURE=true, it will skip signature checking (claims-only).
     */
    public function verify(string $jwt): array
    {
        // Handle both boolean true and string "true"
        $skipSig = filter_var(env('TOOLSUPPORT_SKIP_SIGNATURE', false), FILTER_VALIDATE_BOOLEAN);
        $jwksUrl = trim((string) env('TOOLSUPPORT_JWKS_URL', ''));

        if ($skipSig) {
            $claims = $this->unsafeDecodeClaims($jwt);
        } else {
            if ($jwksUrl === '') {
                // We can still decode claims, but we refuse to treat it as verified.
                // For a real integration, set TOOLSUPPORT_JWKS_URL.
                throw new \RuntimeException('TOOLSUPPORT_JWKS_URL is not set and signature verification is enabled.');
            }

            $jwks = Cache::remember('toolsupport:jwks:' . hash('sha256', $jwksUrl), 3600, function () use ($jwksUrl) {
                $raw = @file_get_contents($jwksUrl);
                if ($raw === false) {
                    throw new \RuntimeException('Failed to fetch JWKS from TOOLSUPPORT_JWKS_URL.');
                }
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) {
                    throw new \RuntimeException('JWKS response was not valid JSON.');
                }
                return $decoded;
            });

            $keys = JWK::parseKeySet($jwks);
            $obj = JWT::decode($jwt, $keys);
            $claims = json_decode(json_encode($obj), true);
        }

        $this->validateClaims($claims);
        return $claims;
    }

    private function validateClaims(array $claims): void
    {
        $now = time();

        $exp = $claims['exp'] ?? null;
        if (!is_int($exp) && !is_float($exp)) {
            throw new \RuntimeException('Missing or invalid exp claim.');
        }
        if ($exp < $now) {
            throw new \RuntimeException('Token has expired.');
        }

        $issExpected = trim((string) env('TOOLSUPPORT_JWT_ISS', ''));
        if ($issExpected !== '') {
            $iss = $claims['iss'] ?? null;
            if (!is_string($iss) || $iss !== $issExpected) {
                throw new \RuntimeException('Invalid iss claim.');
            }
        }

        $audExpected = trim((string) env('TOOLSUPPORT_JWT_AUD', ''));
        if ($audExpected !== '') {
            $aud = $claims['aud'] ?? null;
            $ok = false;
            if (is_string($aud)) {
                $ok = ($aud === $audExpected);
            } elseif (is_array($aud)) {
                $ok = in_array($audExpected, $aud, true);
            }
            if (!$ok) {
                throw new \RuntimeException('Invalid aud claim.');
            }
        }
    }

    private function unsafeDecodeClaims(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) < 2) {
            throw new \RuntimeException('Not a JWT.');
        }
        $payload = $this->b64urlDecode($parts[1]);
        $claims = json_decode($payload, true);
        if (!is_array($claims)) {
            throw new \RuntimeException('JWT payload was not valid JSON.');
        }
        return $claims;
    }

    private function b64urlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        $data = strtr($data, '-_', '+/');
        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            throw new \RuntimeException('Invalid base64url payload.');
        }
        return $decoded;
    }
}
