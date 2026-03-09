<?php

namespace Tests\Feature;

use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeneratePreviewRevisionUsesBasePackageTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_revision_uses_base_package_as_llm_baseline(): void
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

        $baseHtml = '<div id="app">PREVIEW_BASE_HTML</div>';

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'title' => 'Revised app',
                            'html' => $baseHtml,
                            'css' => '',
                            'js' => '',
                        ], JSON_UNESCAPED_SLASHES),
                    ],
                ]],
                'usage' => ['total_tokens' => 10],
            ], 200),
            'https://api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'title' => 'Revised app',
                    'html' => $baseHtml,
                    'css' => '',
                    'js' => '',
                ], JSON_UNESCAPED_SLASHES),
                'usage' => ['total_tokens' => 10],
            ], 200),
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/apps/generate', [
                'prompt' => 'Change styling only.',
                'app_id' => $appId,
                'preview' => true,
                'generation_mode' => 'open_interaction',
                'base_package' => [
                    'kind' => 'open_interaction',
                    'title' => 'Preview title',
                    'html' => $baseHtml,
                    'css' => '/* preview css */',
                    'js' => '// preview js',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('change_summary.changed', true)
            ->assertJsonPath('change_summary.metrics.html_changed', false)
            ->assertJsonPath('change_summary.metrics.css_changed', true)
            ->assertJsonPath('change_summary.metrics.js_changed', true)
            ->assertJsonPath('change_summary.metrics.title_changed', true);

        Http::assertSent(function ($request) {
            $payload = $request->data();
            $messages = $payload['messages'] ?? [];
            $input = $payload['input'] ?? [];

            $messageStrings = array_map(fn ($m) => (string) ($m['content'] ?? ''), $messages);
            $inputStrings = [];
            foreach ($input as $item) {
                $content = $item['content'] ?? [];
                foreach ($content as $part) {
                    if (($part['type'] ?? null) === 'input_text' && is_string($part['text'] ?? null)) {
                        $inputStrings[] = $part['text'];
                    }
                }
            }

            $combined = implode("\n", array_merge($messageStrings, $inputStrings));

            return str_contains($combined, 'PREVIEW_BASE_HTML')
                && !str_contains($combined, 'SAVED_HTML');
        });
    }

    private function buildAuthToken(): string
    {
        $secret = 'test-local-secret-preview-base-1234567890';
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
                'deployment_id' => 'deployment-preview-base',
                'resource_link_id' => 'resource-preview-base',
                'message_type' => 'LtiResourceLinkRequest',
                'is_instructor' => true,
            ],
        ], $secret, 'HS256');
    }
}
