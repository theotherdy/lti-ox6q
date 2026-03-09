<?php

namespace Tests\Feature;

use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GenerateRevisionNoOpSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_op_revision_returns_change_summary_and_human_summary(): void
    {
        $token = $this->buildAuthToken();

        $appId = DB::table('apps')->insertGetId([
            'title' => 'Saved title',
            'kind' => 'open_interaction',
            'html' => '<div id="app">SAVED_HTML</div>',
            'css' => '/* saved css */',
            'js' => '// saved js',
            'structured_json' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $base = [
            'kind' => 'open_interaction',
            'title' => 'Preview title',
            'html' => '<div id="app">PREVIEW_BASE_HTML</div>',
            'css' => '/* preview css */',
            'js' => '// preview js',
        ];

        $payload = [
            'title' => $base['title'],
            'html' => $base['html'],
            'css' => $base['css'],
            'js' => $base['js'],
        ];

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode($payload, JSON_UNESCAPED_SLASHES),
                    ],
                ]],
                'usage' => ['total_tokens' => 10],
            ], 200),
            'https://api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode($payload, JSON_UNESCAPED_SLASHES),
                'usage' => ['total_tokens' => 10],
            ], 200),
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/apps/generate', [
                'prompt' => 'Please improve this.',
                'app_id' => $appId,
                'preview' => true,
                'generation_mode' => 'open_interaction',
                'base_package' => $base,
            ])
            ->assertOk()
            ->assertJsonPath('change_summary.changed', false)
            ->assertJsonPath('no_op_revision_detected', true)
            ->assertJsonCount(0, 'change_summary.changed_sections')
            ->assertJsonPath('human_change_summary.0', 'The revision mostly reproduced the previous version.');
    }

    private function buildAuthToken(): string
    {
        $secret = 'test-local-secret-no-op-123456789012';
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
                'deployment_id' => 'deployment-no-op',
                'resource_link_id' => 'resource-no-op',
                'message_type' => 'LtiResourceLinkRequest',
                'is_instructor' => true,
            ],
        ], $secret, 'HS256');
    }
}
