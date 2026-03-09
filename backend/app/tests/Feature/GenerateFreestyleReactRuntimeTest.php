<?php

namespace Tests\Feature;

use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GenerateFreestyleReactRuntimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_interaction_generation_returns_runtime_and_source_jsx(): void
    {
        $token = $this->buildAuthToken();
        $appId = DB::table('apps')->insertGetId([
            'title' => 'Seed app',
            'kind' => 'open_interaction',
            'html' => '<div id="app"></div>',
            'css' => '',
            'js' => '',
            'source_jsx' => '',
            'structured_json' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = json_encode([
            'title' => 'React App',
            'html' => '<div id="app"></div>',
            'css' => '.x { color: red; }',
            'js' => 'const root = ReactDOM.createRoot(document.getElementById("app")); root.render(React.createElement("div", null, "Hello"));',
        ]);

        Http::fake([
            'https://api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => $payload,
                    ],
                ]],
                'output_text' => $payload,
            ]),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/apps/generate', [
                'prompt' => 'Build a simple app',
                'app_id' => $appId,
                'preview' => true,
                'generation_mode' => 'open_interaction',
            ])
            ->assertOk()
            ->assertJsonPath('kind', 'open_interaction')
            ->assertJsonPath('runtime', 'react_jsx')
            ->assertJsonPath('transpile_status', 'ok');

        $sourceJsx = $response->json('source_jsx');
        $runtimeJs = $response->json('js');

        $this->assertIsString($sourceJsx);
        $this->assertStringContainsString('ReactDOM.createRoot', $sourceJsx);
        $this->assertIsString($runtimeJs);
        $this->assertStringContainsString('window.React', $runtimeJs);
    }

    private function buildAuthToken(): string
    {
        $secret = 'test-local-secret-generate-react-12345';
        putenv("LOCAL_JWT_SECRET={$secret}");
        $_ENV['LOCAL_JWT_SECRET'] = $secret;
        $_SERVER['LOCAL_JWT_SECRET'] = $secret;

        return JWT::encode([
            'iss' => 'http://localhost',
            'sub' => 'u-react',
            'iat' => time(),
            'exp' => time() + 3600,
            'roles' => ['Instructor'],
            'launch_mode' => 'resource',
            'lti' => [
                'issuer' => 'https://lti-dev.canvas.ox.ac.uk',
                'deployment_id' => 'deployment-react',
                'resource_link_id' => 'resource-react',
                'message_type' => 'LtiResourceLinkRequest',
                'is_instructor' => true,
            ],
        ], $secret, 'HS256');
    }
}
