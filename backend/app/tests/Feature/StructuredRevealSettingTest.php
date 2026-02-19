<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StructuredRevealSettingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
    }

    public function test_save_revision_accepts_reveal_setting_true_and_false(): void
    {
        $appId = DB::table('apps')->insertGetId([
            'title' => 'Existing app',
            'kind' => 'structured_question_set',
            'structured_json' => json_encode(['kind' => 'structured_question_set', 'schema_version' => '2026-02-06', 'title' => 'Existing app', 'questions' => [], 'meta' => []]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([true, false] as $revealSetting) {
            $response = $this->putJson("/api/apps/{$appId}/save-revision", [
                'kind' => 'structured_question_set',
                'schema_version' => '2026-02-18',
                'title' => 'Revised app',
                'questions' => [$this->buildSingleChoiceQuestion($revealSetting)],
                'meta' => ['mode' => 'self_test'],
            ]);

            $response->assertOk()->assertJson(['success' => true]);

            $stored = DB::table('apps')->where('id', $appId)->value('structured_json');
            $decoded = json_decode((string) $stored, true);

            $this->assertIsArray($decoded);
            $this->assertSame($revealSetting, $decoded['questions'][0]['reveal_correct_after_two_incorrect_attempts']);
        }
    }

    public function test_save_revision_rejects_non_boolean_reveal_setting(): void
    {
        $appId = DB::table('apps')->insertGetId([
            'title' => 'Existing app',
            'kind' => 'structured_question_set',
            'structured_json' => json_encode(['kind' => 'structured_question_set', 'schema_version' => '2026-02-06', 'title' => 'Existing app', 'questions' => [], 'meta' => []]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->putJson("/api/apps/{$appId}/save-revision", [
            'kind' => 'structured_question_set',
            'schema_version' => '2026-02-18',
            'title' => 'Revised app',
            'questions' => [$this->buildSingleChoiceQuestion('yes')],
            'meta' => ['mode' => 'self_test'],
        ]);

        $response
            ->assertStatus(422)
            ->assertJson(['error' => 'reveal_correct_after_two_incorrect_attempts must be a boolean when provided']);
    }

    /**
     * @param bool|string $revealSetting
     */
    private function buildSingleChoiceQuestion($revealSetting): array
    {
        return [
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
            'reveal_correct_after_two_incorrect_attempts' => $revealSetting,
        ];
    }
}
