<?php

namespace Tests\Feature;

use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AppStateSummaryAndResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_state_summary_counts_instructor_and_non_instructor_state(): void
    {
        $secret = $this->setLocalJwtSecret();

        $appId = DB::table('apps')->insertGetId([
            'title' => 'Summary app',
            'kind' => 'open_interaction',
            'html' => '<div id="app"></div>',
            'css' => '',
            'js' => '',
            'structured_json' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $instructorUserId = DB::table('lti_users')->insertGetId([
            'sub' => 'instructor-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $studentUserId = DB::table('lti_users')->insertGetId([
            'sub' => 'student-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('app_states')->insert([
            [
                'app_id' => $appId,
                'lti_user_id' => $instructorUserId,
                'state_json' => json_encode(['score' => 1]),
                'is_instructor' => true,
                'roles_json' => json_encode(['Instructor']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'app_id' => $appId,
                'lti_user_id' => $studentUserId,
                'state_json' => json_encode(['score' => 0]),
                'is_instructor' => false,
                'roles_json' => json_encode(['Learner']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $token = $this->buildAuthToken($secret, 'summary-user', true);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/apps/{$appId}/state-summary")
            ->assertOk()
            ->assertJson([
                'total_state_count' => 2,
                'instructor_state_count' => 1,
                'non_instructor_state_count' => 1,
                'has_non_instructor_state' => true,
            ]);
    }

    public function test_save_revision_with_reset_non_instructor_state_preserves_instructor_rows_only(): void
    {
        $secret = $this->setLocalJwtSecret();

        $appId = DB::table('apps')->insertGetId([
            'title' => 'Existing structured app',
            'kind' => 'structured_question_set',
            'structured_json' => json_encode([
                'kind' => 'structured_question_set',
                'schema_version' => '2026-02-18',
                'title' => 'Existing structured app',
                'questions' => [],
                'meta' => ['mode' => 'self_test'],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $instructorUserId = DB::table('lti_users')->insertGetId([
            'sub' => 'instructor-2',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $studentUserId = DB::table('lti_users')->insertGetId([
            'sub' => 'student-2',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('app_states')->insert([
            [
                'app_id' => $appId,
                'lti_user_id' => $instructorUserId,
                'state_json' => json_encode(['attempts' => 1]),
                'is_instructor' => true,
                'roles_json' => json_encode(['Instructor']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'app_id' => $appId,
                'lti_user_id' => $studentUserId,
                'state_json' => json_encode(['attempts' => 3]),
                'is_instructor' => false,
                'roles_json' => json_encode(['Learner']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $token = $this->buildAuthToken($secret, 'editor-user', true);

        $payload = [
            'kind' => 'structured_question_set',
            'schema_version' => '2026-02-18',
            'title' => 'Revised structured app',
            'questions' => [[
                'id' => 'q1',
                'question_type' => 'multiple_choice_single_answer',
                'prompt_html' => '<p>2 + 2 = ?</p>',
                'points_possible' => 1,
                'shuffle_options' => false,
                'options' => [
                    ['id' => 'a', 'text' => '3'],
                    ['id' => 'b', 'text' => '4'],
                ],
                'correct_option_id' => 'b',
                'reveal_correct_after_two_incorrect_attempts' => true,
            ]],
            'meta' => ['mode' => 'self_test'],
            'reset_non_instructor_state' => true,
        ];

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/apps/{$appId}/save-revision", $payload)
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame(1, DB::table('app_states')->where('app_id', $appId)->count());
        $this->assertSame(1, DB::table('app_states')->where('app_id', $appId)->where('is_instructor', true)->count());
        $this->assertSame(0, DB::table('app_states')->where('app_id', $appId)->where('is_instructor', false)->count());
    }

    private function setLocalJwtSecret(): string
    {
        $secret = 'test-local-secret-123456789012345678901234';
        putenv("LOCAL_JWT_SECRET={$secret}");
        $_ENV['LOCAL_JWT_SECRET'] = $secret;
        $_SERVER['LOCAL_JWT_SECRET'] = $secret;

        return $secret;
    }

    private function buildAuthToken(string $secret, string $sub, bool $isInstructor): string
    {
        return JWT::encode([
            'iss' => 'http://localhost',
            'sub' => $sub,
            'iat' => time(),
            'exp' => time() + 3600,
            'roles' => $isInstructor ? ['Instructor'] : ['Learner'],
            'launch_mode' => 'resource',
            'lti' => [
                'issuer' => 'https://lti-dev.canvas.ox.ac.uk',
                'deployment_id' => 'deployment-state',
                'resource_link_id' => 'resource-state',
                'message_type' => 'LtiResourceLinkRequest',
                'is_instructor' => $isInstructor,
            ],
        ], $secret, 'HS256');
    }
}
