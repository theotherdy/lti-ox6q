<?php

namespace Tests\Feature;

use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GenerateIncludesImageAssetContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_interaction_generation_includes_available_image_assets_context(): void
    {
        $token = $this->buildAuthToken();
        $appId = DB::table('apps')->insertGetId([
            'title' => 'Existing app',
            'kind' => 'open_interaction',
            'html' => '<div id="app"></div>',
            'css' => '',
            'js' => '',
            'structured_json' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('app_assets')->insert([
            'id' => 'asset-1',
            'app_id' => $appId,
            'kind' => 'image',
            'disk' => 'public',
            'path_optimized' => 'apps/' . $appId . '/assets/asset-1/optimized.webp',
            'path_original' => null,
            'url_optimized' => 'https://learntech.example/storage/apps/' . $appId . '/assets/asset-1/optimized.webp',
            'url_original' => null,
            'mime_original' => 'image/png',
            'mime_optimized' => 'image/webp',
            'bytes_original' => 1200,
            'bytes_optimized' => 900,
            'width' => 800,
            'height' => 600,
            'checksum_sha256' => str_repeat('a', 64),
            'label' => 'Microscope image',
            'alt_text' => 'Microscope slide',
            'rights_basis' => 'public_domain',
            'cc_license' => null,
            'copyright_holder' => null,
            'rights_note' => null,
            'rights_declared_by_sub' => 'editor-user',
            'rights_declared_at' => now(),
            'created_by_sub' => 'editor-user',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'title' => 'Revised app',
                            'html' => '<div id="app"><img src="https://learntech.example/storage/apps/' . $appId . '/assets/asset-1/optimized.webp"></div>',
                            'css' => '',
                            'js' => '',
                        ], JSON_UNESCAPED_SLASHES),
                    ],
                ]],
                'usage' => ['total_tokens' => 10],
            ], 200),
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/apps/generate', [
                'prompt' => 'Use the microscope image in the interface.',
                'app_id' => $appId,
                'preview' => true,
                'generation_mode' => 'open_interaction',
            ])
            ->assertOk()
            ->assertJsonPath('kind', 'open_interaction');

        Http::assertSent(function ($request) {
            $payload = $request->data();
            $messages = $payload['messages'] ?? [];
            $combined = implode("\n", array_map(fn ($m) => (string) ($m['content'] ?? ''), $messages));
            return str_contains($combined, 'AVAILABLE_IMAGE_ASSETS')
                && str_contains($combined, 'asset-1')
                && str_contains($combined, 'Microscope image')
                && str_contains($combined, 'rights_basis');
        });
    }

    private function buildAuthToken(): string
    {
        $secret = 'test-local-secret-generate-1234567890123';
        putenv("LOCAL_JWT_SECRET={$secret}");
        $_ENV['LOCAL_JWT_SECRET'] = $secret;
        $_SERVER['LOCAL_JWT_SECRET'] = $secret;

        return JWT::encode([
            'iss' => 'http://localhost',
            'sub' => 'editor-user',
            'iat' => time(),
            'exp' => time() + 3600,
            'roles' => ['Instructor'],
            'launch_mode' => 'resource',
            'lti' => [
                'issuer' => 'https://lti-dev.canvas.ox.ac.uk',
                'deployment_id' => 'deployment-generate',
                'resource_link_id' => 'resource-generate',
                'message_type' => 'LtiResourceLinkRequest',
                'is_instructor' => true,
            ],
        ], $secret, 'HS256');
    }
}
