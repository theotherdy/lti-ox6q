<?php

namespace Tests\Unit;

use App\Http\Controllers\GenerateAppController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class GenerateStructuredRevealNormalizationTest extends TestCase
{
    public function test_normalization_defaults_reveal_setting_to_true_when_omitted(): void
    {
        $controller = new GenerateAppController();
        $method = new ReflectionMethod(GenerateAppController::class, 'normalizeStructuredQuestionSetByType');
        $method->setAccessible(true);

        $payload = [
            'title' => 'Question',
            'questions' => [$this->buildSingleChoiceQuestionWithoutReveal()],
        ];

        $result = $method->invoke($controller, $payload, 'multiple_choice_single_answer', 1.0, null);

        $this->assertIsArray($result);
        $this->assertSame(true, $result['questions'][0]['reveal_correct_after_two_incorrect_attempts']);
    }

    public function test_normalization_preserves_existing_false_when_new_payload_omits_reveal_setting(): void
    {
        $controller = new GenerateAppController();
        $method = new ReflectionMethod(GenerateAppController::class, 'normalizeStructuredQuestionSetByType');
        $method->setAccessible(true);

        $payload = [
            'title' => 'Question',
            'questions' => [$this->buildSingleChoiceQuestionWithoutReveal()],
        ];

        $existingStructured = [
            'title' => 'Existing',
            'questions' => [[
                'id' => 'q_existing',
                'question_type' => 'multiple_choice_single_answer',
                'prompt_html' => '<p>Existing</p>',
                'points_possible' => 1,
                'shuffle_options' => false,
                'options' => [
                    ['id' => 'a', 'text' => 'A'],
                    ['id' => 'b', 'text' => 'B'],
                ],
                'correct_option_id' => 'a',
                'reveal_correct_after_two_incorrect_attempts' => false,
            ]],
            'meta' => ['mode' => 'self_test'],
        ];

        $result = $method->invoke(
            $controller,
            $payload,
            'multiple_choice_single_answer',
            1.0,
            $existingStructured
        );

        $this->assertIsArray($result);
        $this->assertSame(false, $result['questions'][0]['reveal_correct_after_two_incorrect_attempts']);
    }

    private function buildSingleChoiceQuestionWithoutReveal(): array
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
        ];
    }
}
