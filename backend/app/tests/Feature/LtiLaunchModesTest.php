<?php

namespace Tests\Feature;

use App\Services\ToolSupportJwtVerifier;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LtiLaunchModesTest extends TestCase
{
    use RefreshDatabase;

    public function test_bootstrap_deep_linking_includes_launch_context_and_resolves_custom_app_id(): void
    {
        $this->setLocalJwtSecret();

        $appId = DB::table('apps')->insertGetId([
            'title' => 'Custom linked app',
            'kind' => 'open_interaction',
            'html' => '<div id="app"></div>',
            'css' => '',
            'js' => '',
            'structured_json' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $claims = [
            'sub' => 'user-123',
            'nonce' => 'nonce-123',
            'jti' => 'jti-123',
            'iss' => 'https://lti-dev.canvas.ox.ac.uk',
            'https://purl.imsglobal.org/spec/lti/claim/message_type' => 'LtiDeepLinkingRequest',
            'https://purl.imsglobal.org/spec/lti/claim/deployment_id' => 'deployment-123',
            'https://purl.imsglobal.org/spec/lti/claim/resource_link' => ['id' => 'resource-123'],
            'https://purl.imsglobal.org/spec/lti/claim/roles' => [
                'http://purl.imsglobal.org/vocab/lis/v2/membership#Instructor',
            ],
            'https://purl.imsglobal.org/spec/lti-dl/claim/deep_linking_settings' => [
                'deep_link_return_url' => 'https://canvas.example/deep-link-return',
            ],
            'https://purl.imsglobal.org/spec/lti/claim/custom' => [
                'ox6q_app_id' => (string) $appId,
            ],
            'https://purl.imsglobal.org/spec/lti/claim/launch_presentation' => [
                'return_url' => 'https://canvas.example/return',
            ],
            'https://purl.imsglobal.org/spec/lti/claim/target_link_uri' => 'https://tool.example/launch',
        ];

        $this->app->instance(ToolSupportJwtVerifier::class, $this->buildVerifierStub($claims));

        $response = $this->postJson('/api/auth/bootstrap', [
            'tool_support_jwt' => 'fake.jwt.value',
        ]);

        $response->assertOk()
            ->assertJson([
                'launch_mode' => 'deep_linking',
                'app_id' => $appId,
                'lti' => [
                    'message_type' => 'LtiDeepLinkingRequest',
                    'is_instructor' => true,
                    'target_link_uri' => 'https://tool.example/launch',
                    'custom' => [
                        'ox6q_app_id' => (string) $appId,
                    ],
                ],
            ]);

        $token = $response->json('access_token');
        $decoded = $this->decodeJwtPayload($token);
        $this->assertSame('deep_linking', $decoded['launch_mode'] ?? null);
        $this->assertSame('LtiDeepLinkingRequest', $decoded['lti']['message_type'] ?? null);
    }

    public function test_bootstrap_prefers_custom_app_id_over_resource_link_mapping(): void
    {
        $this->setLocalJwtSecret();

        $mappedAppId = DB::table('apps')->insertGetId([
            'title' => 'Mapped app',
            'kind' => 'open_interaction',
            'html' => '<div id="app"></div>',
            'css' => '',
            'js' => '',
            'structured_json' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customAppId = DB::table('apps')->insertGetId([
            'title' => 'Custom app',
            'kind' => 'open_interaction',
            'html' => '<div id="app"></div>',
            'css' => '',
            'js' => '',
            'structured_json' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('resource_links')->insert([
            'issuer' => 'https://lti-dev.canvas.ox.ac.uk',
            'deployment_id' => 'deployment-abc',
            'resource_link_id' => 'resource-abc',
            'app_id' => $mappedAppId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $claims = [
            'sub' => 'user-abc',
            'nonce' => 'nonce-abc',
            'jti' => 'jti-abc',
            'iss' => 'https://lti-dev.canvas.ox.ac.uk',
            'https://purl.imsglobal.org/spec/lti/claim/message_type' => 'LtiResourceLinkRequest',
            'https://purl.imsglobal.org/spec/lti/claim/deployment_id' => 'deployment-abc',
            'https://purl.imsglobal.org/spec/lti/claim/resource_link' => ['id' => 'resource-abc'],
            'https://purl.imsglobal.org/spec/lti/claim/custom' => [
                'ox6q_app_id' => (string) $customAppId,
            ],
        ];

        $this->app->instance(ToolSupportJwtVerifier::class, $this->buildVerifierStub($claims));

        $response = $this->postJson('/api/auth/bootstrap', [
            'tool_support_jwt' => 'fake.jwt.value',
        ]);

        $response->assertOk()
            ->assertJson([
                'app_id' => $customAppId,
                'launch_mode' => 'resource',
            ]);
    }

    public function test_local_jwt_auth_allows_deep_link_launch_without_resource_link_but_rejects_resource_launch_without_it(): void
    {
        $secret = $this->setLocalJwtSecret();

        $appId = DB::table('apps')->insertGetId([
            'title' => 'Launch app',
            'kind' => 'open_interaction',
            'html' => '<div id="app"></div>',
            'css' => '',
            'js' => '',
            'structured_json' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $deepLinkToken = JWT::encode([
            'iss' => 'http://localhost',
            'sub' => 'deep-link-user',
            'iat' => time(),
            'exp' => time() + 3600,
            'launch_mode' => 'deep_linking',
            'lti' => [
                'issuer' => 'https://lti-dev.canvas.ox.ac.uk',
                'deployment_id' => 'deployment-deep',
                'message_type' => 'LtiDeepLinkingRequest',
                'is_instructor' => true,
            ],
        ], $secret, 'HS256');

        $resourceTokenMissingResourceLink = JWT::encode([
            'iss' => 'http://localhost',
            'sub' => 'resource-user',
            'iat' => time(),
            'exp' => time() + 3600,
            'launch_mode' => 'resource',
            'lti' => [
                'issuer' => 'https://lti-dev.canvas.ox.ac.uk',
                'deployment_id' => 'deployment-resource',
                'message_type' => 'LtiResourceLinkRequest',
            ],
        ], $secret, 'HS256');

        $this->withHeader('Authorization', "Bearer {$deepLinkToken}")
            ->getJson("/api/apps/{$appId}/package")
            ->assertOk();

        $this->withHeader('Authorization', "Bearer {$resourceTokenMissingResourceLink}")
            ->getJson("/api/apps/{$appId}/package")
            ->assertStatus(401)
            ->assertJson([
                'error' => 'Token missing required LTI resource_link_id claim.',
            ]);
    }

    private function setLocalJwtSecret(): string
    {
        $secret = 'test-local-secret-123456789012345678901234';
        putenv("LOCAL_JWT_SECRET={$secret}");
        $_ENV['LOCAL_JWT_SECRET'] = $secret;
        $_SERVER['LOCAL_JWT_SECRET'] = $secret;

        return $secret;
    }

    private function buildVerifierStub(array $claims): ToolSupportJwtVerifier
    {
        return new class($claims) extends ToolSupportJwtVerifier {
            public function __construct(private readonly array $claims)
            {
            }

            public function verify(string $jwt): array
            {
                return $this->claims;
            }
        };
    }

    private function decodeJwtPayload(string $token): array
    {
        $parts = explode('.', $token);
        $payload = $parts[1] ?? '';
        $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
        $json = base64_decode(strtr($payload, '-_', '+/'), true);
        if ($json === false) {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
