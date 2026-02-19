<?php

namespace Tests\Feature;

use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StructuredHtmlSanitizationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_save_revision_sanitizes_structured_prompt_html_before_persist(): void
    {
        $secret = $this->setLocalJwtSecret();
        $token = $this->buildAuthToken($secret, 'editor-user');

        $appId = DB::table('apps')->insertGetId([
            'title' => 'Structured app',
            'kind' => 'structured_question_set',
            'structured_json' => json_encode([
                'kind' => 'structured_question_set',
                'schema_version' => '2026-02-18',
                'title' => 'Structured app',
                'questions' => [],
                'meta' => [],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = [
            'kind' => 'structured_question_set',
            'schema_version' => '2026-02-18',
            'title' => 'Revised structured app',
            'questions' => [[
                'id' => 'q1',
                'question_type' => 'multiple_choice_single_answer',
                'prompt_html' => '<p>Read this</p><table><thead><tr><th scope="col">A</th></tr></thead><tbody><tr><td>1</td></tr></tbody></table><a href="https://example.org">link</a><script>alert(1)</script><img src="https://safe.example/img.png"><img src="http://unsafe.example/img.png">',
                'points_possible' => 1,
                'shuffle_options' => false,
                'options' => [
                    ['id' => 'a', 'text' => 'Option A'],
                    ['id' => 'b', 'text' => 'Option B'],
                ],
                'correct_option_id' => 'a',
                'reveal_correct_after_two_incorrect_attempts' => true,
            ]],
            'meta' => ['mode' => 'self_test'],
        ];

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/apps/{$appId}/save-revision", $payload)
            ->assertOk()
            ->assertJson(['success' => true]);

        $stored = DB::table('apps')->where('id', $appId)->value('structured_json');
        $decoded = json_decode((string) $stored, true);
        $promptHtml = $decoded['questions'][0]['prompt_html'] ?? '';

        $this->assertStringContainsString('<table>', $promptHtml);
        $this->assertStringContainsString('scope="col"', $promptHtml);
        $this->assertStringContainsString('src="https://safe.example/img.png"', $promptHtml);
        $this->assertStringNotContainsString('<a ', $promptHtml);
        $this->assertStringNotContainsString('<script', $promptHtml);
        $this->assertStringNotContainsString('http://unsafe.example', $promptHtml);
    }

    public function test_package_sanitizes_legacy_structured_prompt_html_on_read(): void
    {
        $secret = $this->setLocalJwtSecret();
        $token = $this->buildAuthToken($secret, 'reader-user');

        $appId = DB::table('apps')->insertGetId([
            'title' => 'Legacy structured app',
            'kind' => 'structured_question_set',
            'structured_json' => json_encode([
                'kind' => 'structured_question_set',
                'schema_version' => '2026-02-18',
                'title' => 'Legacy structured app',
                'questions' => [[
                    'id' => 'q1',
                    'question_type' => 'multiple_choice_single_answer',
                    'prompt_html' => '<p>Legacy</p><table><tbody><tr><td>1</td></tr></tbody></table><a href="https://example.org">link</a><img src="http://unsafe.example/img.png">',
                    'points_possible' => 1,
                    'shuffle_options' => false,
                    'options' => [
                        ['id' => 'a', 'text' => 'Option A'],
                        ['id' => 'b', 'text' => 'Option B'],
                    ],
                    'correct_option_id' => 'a',
                    'reveal_correct_after_two_incorrect_attempts' => true,
                ]],
                'meta' => ['mode' => 'self_test'],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/apps/{$appId}/package")
            ->assertOk();

        $promptHtml = $response->json('questions.0.prompt_html');
        $this->assertIsString($promptHtml);
        $this->assertStringContainsString('<table>', $promptHtml);
        $this->assertStringNotContainsString('<a ', $promptHtml);
        $this->assertStringNotContainsString('http://unsafe.example', $promptHtml);
    }

    private function setLocalJwtSecret(): string
    {
        $secret = 'test-local-secret-123456789012345678901234';
        putenv("LOCAL_JWT_SECRET={$secret}");
        $_ENV['LOCAL_JWT_SECRET'] = $secret;
        $_SERVER['LOCAL_JWT_SECRET'] = $secret;

        return $secret;
    }

    private function buildAuthToken(string $secret, string $sub): string
    {
        return JWT::encode([
            'iss' => 'http://localhost',
            'sub' => $sub,
            'iat' => time(),
            'exp' => time() + 3600,
            'roles' => ['Instructor'],
            'launch_mode' => 'resource',
            'lti' => [
                'issuer' => 'https://lti-dev.canvas.ox.ac.uk',
                'deployment_id' => 'deployment-state',
                'resource_link_id' => 'resource-state',
                'message_type' => 'LtiResourceLinkRequest',
                'is_instructor' => true,
            ],
        ], $secret, 'HS256');
    }
}
