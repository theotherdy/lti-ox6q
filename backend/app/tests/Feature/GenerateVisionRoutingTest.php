<?php

namespace Tests\Feature;

use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GenerateVisionRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_prompt_with_pic_keyword_triggers_single_pass_vision_and_uses_up_to_configured_assets(): void
    {
        putenv('OPENAI_VISION_ENABLED=true');
        putenv('OPENAI_VISION_TWO_PASS=false');
        putenv('OPENAI_VISION_MAX_IMAGES=10');

        $token = $this->buildAuthToken();
        $appId = DB::table('apps')->insertGetId([
            'title' => 'Existing app',
            'kind' => 'open_interaction',
            'html' => '<div id="app"></div>',
            'css' => '',
            'js' => '',
            'structured_json' => null,
            'lifecycle_status' => 'draft_uninserted',
            'inserted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $path = "apps/{$appId}/assets/asset-vision-1/optimized.webp";
        Storage::disk('public')->put($path, 'fake-image-bytes-123');

        DB::table('app_assets')->insert([
            'id' => 'asset-vision-1',
            'app_id' => $appId,
            'kind' => 'image',
            'disk' => 'public',
            'path_optimized' => $path,
            'path_original' => null,
            'url_optimized' => '/storage/' . $path,
            'url_original' => null,
            'mime_original' => 'image/webp',
            'mime_optimized' => 'image/webp',
            'bytes_original' => 50,
            'bytes_optimized' => 20,
            'width' => 640,
            'height' => 480,
            'checksum_sha256' => str_repeat('c', 64),
            'label' => 'Specimen pic',
            'alt_text' => 'Specimen slide',
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

        Http::fakeSequence()
            ->push([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'title' => 'Revised app',
                            'html' => '<div id="app"><img src="/storage/' . $path . '"></div>',
                            'css' => '',
                            'js' => '',
                        ], JSON_UNESCAPED_SLASHES),
                    ],
                ]],
                'usage' => ['total_tokens' => 30],
            ], 200);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/apps/generate', [
                'prompt' => 'Build an interaction using this pic as the main visual.',
                'app_id' => $appId,
                'preview' => true,
                'generation_mode' => 'open_interaction',
            ])
            ->assertOk();

        $response
            ->assertJsonPath('generation_path', 'vision_direct')
            ->assertJsonPath('used_asset_ids.0', 'asset-vision-1');

        Http::assertSentCount(1);
    }

    private function buildAuthToken(): string
    {
        $secret = 'test-local-secret-vision-123456789012';
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
                'deployment_id' => 'deployment-vision',
                'resource_link_id' => 'resource-vision',
                'message_type' => 'LtiResourceLinkRequest',
                'is_instructor' => true,
            ],
        ], $secret, 'HS256');
    }
}
