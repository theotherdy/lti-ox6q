<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Services\StructuredHtmlSanitizer;

class GenerateAppController extends Controller
{
    private const KIND_OPEN_INTERACTION = 'open_interaction';
    private const KIND_STRUCTURED_QUESTION_SET = 'structured_question_set';
    private const SCHEMA_VERSION = '2026-02-18';

    private const QUESTION_TYPE_MC_SINGLE = 'multiple_choice_single_answer';
    private const QUESTION_TYPE_MC_MULTIPLE = 'multiple_choice_multiple_answer';
    private const QUESTION_TYPE_MATCHING = 'matching';
    private const QUESTION_TYPE_FILL_IN_BLANK = 'fill_in_blank';
    private const QUESTION_TYPE_ORDERING = 'ordering';
    private const QUESTION_TYPE_NUMERIC = 'numeric';
    private const QUESTION_TYPE_IMAGE_HOTSPOT_SINGLE = 'image_hotspot_single';

    private const STRUCTURED_TYPES = [
        self::QUESTION_TYPE_MC_SINGLE,
        self::QUESTION_TYPE_MC_MULTIPLE,
        self::QUESTION_TYPE_MATCHING,
        self::QUESTION_TYPE_FILL_IN_BLANK,
        self::QUESTION_TYPE_ORDERING,
        self::QUESTION_TYPE_NUMERIC,
        self::QUESTION_TYPE_IMAGE_HOTSPOT_SINGLE,
    ];

    private function callLLM(array $messages, ?string $model = null): array
    {
        $startTime = microtime(true);

        try {
            $res = Http::withToken(config('services.openai.key'))
                ->timeout((int) env('OPENAI_TIMEOUT', 180))
                ->retry(2, 1000)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model ?: env('OPENAI_MODEL', 'gpt-4.1-mini'),
                    'temperature' => (float) env('OPENAI_TEMPERATURE', 0.3),
                    'messages' => $messages,
                ]);

            $duration = microtime(true) - $startTime;

            if ($res->status() >= 400) {
                Log::error('OpenAI API request failed', [
                    'status' => $res->status(),
                    'body' => $res->body(),
                    'duration' => round($duration, 2) . 's',
                ]);
                return ['package' => null, 'error' => 'Failed to generate app. Please try again.', 'raw' => null];
            }

            $responseData = $res->json();

            Log::info('OpenAI API request succeeded', [
                'duration' => round($duration, 2) . 's',
                'tokens_used' => $responseData['usage']['total_tokens'] ?? null,
            ]);

            $text = $this->extractMessageText($responseData['choices'][0]['message']['content'] ?? '');
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $duration = microtime(true) - $startTime;
            Log::error('OpenAI API connection error', [
                'error' => $e->getMessage(),
                'duration' => round($duration, 2) . 's',
            ]);
            return ['package' => null, 'error' => 'Unable to connect to AI service. Please check your connection and try again.', 'raw' => null];
        } catch (\Exception $e) {
            $duration = microtime(true) - $startTime;
            Log::error('Unexpected error during OpenAI API request', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'duration' => round($duration, 2) . 's',
            ]);
            return ['package' => null, 'error' => 'An unexpected error occurred. Please try again.', 'raw' => null];
        }

        Log::debug('LLM raw response', ['content' => $text]);

        if (!$text) {
            return ['package' => null, 'error' => 'OpenAI returned no content', 'raw' => null];
        }

        $package = $this->parseJsonResponse($text);
        if (!$package) {
            Log::warning('Failed to parse LLM JSON', ['raw' => $text]);
            return ['package' => null, 'error' => 'LLM output could not be parsed as JSON', 'raw' => $text];
        }

        return ['package' => $package, 'error' => null, 'raw' => $text];
    }

    /**
     * @param mixed $content
     */
    private function extractMessageText($content): string
    {
        if (is_string($content)) {
            return $content;
        }
        if (!is_array($content)) {
            return '';
        }

        $parts = [];
        foreach ($content as $item) {
            if (is_string($item)) {
                $parts[] = $item;
                continue;
            }
            if (is_array($item) && isset($item['type']) && $item['type'] === 'text' && is_string($item['text'] ?? null)) {
                $parts[] = $item['text'];
            }
        }
        return trim(implode("\n", $parts));
    }

    private function parseJsonResponse(string $text): ?array
    {
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $clean = trim($text);
        $clean = preg_replace('/^```(?:json)?/i', '', $clean);
        $clean = preg_replace('/```$/', '', $clean);
        $clean = trim($clean);

        $decoded = json_decode($clean, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $clean, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private function validatePackage(array $pkg): array
    {
        $violations = [];

        $html = $pkg['html'] ?? '';
        $js = $pkg['js'] ?? '';

        if (stripos($html, '<form') !== false) {
            $violations[] = 'HTML forms are not allowed (use JavaScript handlers instead)';
        }
        if (stripos($html, 'action=') !== false) {
            $violations[] = 'Form action attributes are not allowed';
        }
        if (stripos($html, '<script') !== false && stripos($html, '<script src') !== false) {
            $violations[] = 'External script tags are not allowed';
        }
        if (stripos($html, '<iframe') !== false) {
            $violations[] = 'Nested iframes are not allowed';
        }

        if (stripos($js, 'fetch(') !== false) {
            $violations[] = 'Network access via fetch() is not allowed';
        }
        if (stripos($js, 'XMLHttpRequest') !== false) {
            $violations[] = 'Network access via XMLHttpRequest is not allowed';
        }
        if (stripos($js, 'window.location') !== false) {
            $violations[] = 'Navigation via window.location is not allowed';
        }
        if (stripos($js, 'document.cookie') !== false) {
            $violations[] = 'Accessing cookies is not allowed';
        }
        if (stripos($js, 'localStorage') !== false || stripos($js, 'sessionStorage') !== false) {
            $violations[] = 'Browser storage APIs are not allowed (use sdk.getState/setState)';
        }

        return $violations;
    }

    private function buildStructuredSystemPrompt(string $questionType, ?array $existingStructured = null, array $availableAssets = []): string
    {
        $existingPart = '';
        $revisionRule = '- Create a new question set from scratch.';
        if ($existingStructured) {
            $existingJson = json_encode($existingStructured, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $existingPart = "CURRENT QUESTION SET:\n{$existingJson}\n\n";
            $revisionRule = '- Apply only requested changes and preserve all other fields unless explicitly changed.';
        }

        $assetContext = $this->buildAvailableAssetsContext($availableAssets);

        $common = <<<TXT
Return ONLY valid JSON with shape:
{
  "title": string,
  "questions": [ { ... } ],
  "meta": {"mode": "self_test"}
}

Global constraints:
- Exactly one item in questions.
- Do not wrap JSON in markdown.
- Do not include commentary outside JSON.
{$revisionRule}
TXT;

        $typeSchema = match ($questionType) {
            self::QUESTION_TYPE_MC_SINGLE => <<<TXT
Question schema:
{
  "id": string,
  "question_type": "multiple_choice_single_answer",
  "prompt_html": string,
  "options": [{"id": string, "text": string}],
  "points_possible": number,
  "shuffle_options": boolean,
  "correct_option_id": string,
  "reveal_correct_after_two_incorrect_attempts": boolean
}
Constraints:
- options: 2..8 unique IDs.
- correct_option_id must match one option id.
TXT,
            self::QUESTION_TYPE_MC_MULTIPLE => <<<TXT
Question schema:
{
  "id": string,
  "question_type": "multiple_choice_multiple_answer",
  "prompt_html": string,
  "options": [{"id": string, "text": string}],
  "points_possible": number,
  "shuffle_options": boolean,
  "correct_option_ids": [string],
  "reveal_correct_after_two_incorrect_attempts": boolean
}
Constraints:
- options: 2..8 unique IDs.
- correct_option_ids: non-empty, unique, subset of option IDs.
TXT,
            self::QUESTION_TYPE_MATCHING => <<<TXT
Question schema:
{
  "id": string,
  "question_type": "matching",
  "prompt_html": string,
  "prompts": [{"id": string, "text": string}],
  "choices": [{"id": string, "text": string}],
  "correct_matches": [{"prompt_id": string, "choice_id": string}],
  "points_possible": number,
  "shuffle_options": boolean,
  "reveal_correct_after_two_incorrect_attempts": boolean
}
Constraints:
- prompts/choices count: 2..8 each.
- one-to-one mapping: exactly one match per prompt and no duplicated choice_id.
TXT,
            self::QUESTION_TYPE_FILL_IN_BLANK => <<<TXT
Question schema:
{
  "id": string,
  "question_type": "fill_in_blank",
  "prompt_html": string,
  "blanks": [{"id": string, "acceptable_answers": [string]}],
  "points_possible": number,
  "shuffle_options": false,
  "reveal_correct_after_two_incorrect_attempts": boolean
}
Constraints:
- Use placeholder tokens in prompt_html: [[blank_id]].
- blanks count: 1..8.
- each blank id must appear in prompt_html.
- acceptable_answers must be non-empty.
TXT,
            self::QUESTION_TYPE_ORDERING => <<<TXT
Question schema:
{
  "id": string,
  "question_type": "ordering",
  "prompt_html": string,
  "items": [{"id": string, "text": string}],
  "correct_order": [string],
  "points_possible": number,
  "shuffle_options": true,
  "reveal_correct_after_two_incorrect_attempts": boolean
}
Constraints:
- items count: 2..8 with unique IDs.
- correct_order contains each item id exactly once.
TXT,
            self::QUESTION_TYPE_NUMERIC => <<<TXT
Question schema:
{
  "id": string,
  "question_type": "numeric",
  "prompt_html": string,
  "answer_mode": "exact" | "tolerance",
  "correct_value": number,
  "target_value": number,
  "tolerance": number,
  "min_value": number,
  "max_value": number,
  "points_possible": number,
  "shuffle_options": false,
  "reveal_correct_after_two_incorrect_attempts": boolean
}
Constraints:
- If answer_mode=exact, provide correct_value.
- If answer_mode=tolerance, provide either:
  A) target_value and tolerance (>=0), OR
  B) min_value and max_value with min_value <= max_value.
TXT,
            self::QUESTION_TYPE_IMAGE_HOTSPOT_SINGLE => <<<TXT
Question schema:
{
  "id": string,
  "question_type": "image_hotspot_single",
  "prompt_html": string,
  "image": {
    "asset_id": string,
    "url": string,
    "alt": string,
    "width": number,
    "height": number
  },
  "hotspots": [
    {"id": string, "x": number, "y": number, "w": number, "h": number, "label": string}
  ],
  "correct_hotspot_id": string,
  "points_possible": number,
  "shuffle_options": false,
  "reveal_correct_after_two_incorrect_attempts": boolean
}
Constraints:
- hotspots count: 1..12.
- normalized geometry: x,y,w,h in [0..1], w/h > 0, and (x+w)<=1, (y+h)<=1.
- correct_hotspot_id must match one hotspot id.
TXT,
            default => ''
        };

        return "You generate structured quiz data for a learning tool.\n\n{$assetContext}\n{$existingPart}{$common}\n\n{$typeSchema}";
    }

    private function generateStructuredQuestionSet(
        string $prompt,
        float $confidence,
        string $questionType,
        ?array $existingStructured = null,
        array $availableAssets = [],
        ?string $modelOverride = null,
        array $visionInputs = [],
        ?string $visionAnalysisText = null
    ): ?array {
        $system = $this->buildStructuredSystemPrompt($questionType, $existingStructured, $availableAssets);
        $action = $existingStructured ? 'Revise the current question set according to this request:' : 'Create a question set according to this request:';
        $analysisChunk = ($visionAnalysisText && trim($visionAnalysisText) !== '')
            ? "\n\nVISION_ANALYSIS:\n{$visionAnalysisText}\nUse it when relevant, but still obey schema strictly."
            : '';

        $userText = "{$action}\n{$prompt}{$analysisChunk}";
        $userContent = $visionInputs !== []
            ? array_merge([['type' => 'text', 'text' => $userText]], $visionInputs)
            : $userText;

        $result = $this->callLLM([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $userContent],
        ], $modelOverride);

        if ($result['error'] || !is_array($result['package'])) {
            return null;
        }

        return $this->normalizeStructuredQuestionSetByType(
            $result['package'],
            $questionType,
            $confidence,
            $existingStructured
        );
    }

    private function normalizeStructuredQuestionSetByType(
        array $payload,
        string $questionType,
        float $confidence,
        ?array $existingStructured = null
    ): ?array {
        $title = $payload['title'] ?? ($existingStructured['title'] ?? 'Generated question');
        if (!is_string($title) || trim($title) === '') {
            $title = 'Generated question';
        }

        $questions = $payload['questions'] ?? null;
        if (!is_array($questions) || count($questions) !== 1 || !is_array($questions[0])) {
            return null;
        }

        $existingQuestion = null;
        if (is_array($existingStructured)
            && isset($existingStructured['questions'])
            && is_array($existingStructured['questions'])
            && isset($existingStructured['questions'][0])
            && is_array($existingStructured['questions'][0])) {
            $existingQuestion = $existingStructured['questions'][0];
        }

        $normalizedQuestion = match ($questionType) {
            self::QUESTION_TYPE_MC_SINGLE => $this->normalizeQuestionMcSingle($questions[0], $existingQuestion),
            self::QUESTION_TYPE_MC_MULTIPLE => $this->normalizeQuestionMcMultiple($questions[0], $existingQuestion),
            self::QUESTION_TYPE_MATCHING => $this->normalizeQuestionMatching($questions[0], $existingQuestion),
            self::QUESTION_TYPE_FILL_IN_BLANK => $this->normalizeQuestionFillInBlank($questions[0], $existingQuestion),
            self::QUESTION_TYPE_ORDERING => $this->normalizeQuestionOrdering($questions[0], $existingQuestion),
            self::QUESTION_TYPE_NUMERIC => $this->normalizeQuestionNumeric($questions[0], $existingQuestion),
            self::QUESTION_TYPE_IMAGE_HOTSPOT_SINGLE => $this->normalizeQuestionImageHotspotSingle($questions[0], $existingQuestion),
            default => null,
        };

        if (!$normalizedQuestion) {
            return null;
        }

        return [
            'kind' => self::KIND_STRUCTURED_QUESTION_SET,
            'schema_version' => self::SCHEMA_VERSION,
            'title' => $title,
            'questions' => [$normalizedQuestion],
            'meta' => [
                'mode' => 'self_test',
                'classification_confidence' => max(0.0, min(1.0, $confidence)),
            ],
        ];
    }

    private function normalizeQuestionBase(array $q, string $expectedType, ?array $existingQuestion = null): ?array
    {
        if (($q['question_type'] ?? null) !== $expectedType) {
            return null;
        }

        $id = $q['id'] ?? 'q_001';
        $promptHtml = $q['prompt_html'] ?? '';
        $pointsPossible = $q['points_possible'] ?? 1;

        if (!is_string($id) || trim($id) === '') {
            $id = 'q_001';
        }
        if (!is_string($promptHtml) || trim($promptHtml) === '') {
            return null;
        }
        if (!is_numeric($pointsPossible) || (float) $pointsPossible <= 0) {
            $pointsPossible = 1;
        }

        $promptHtml = app(StructuredHtmlSanitizer::class)->sanitize($promptHtml);
        if ($promptHtml === '') {
            return null;
        }

        $revealAfterTwoIncorrect = true;
        if (isset($q['reveal_correct_after_two_incorrect_attempts']) && is_bool($q['reveal_correct_after_two_incorrect_attempts'])) {
            $revealAfterTwoIncorrect = $q['reveal_correct_after_two_incorrect_attempts'];
        } elseif (is_array($existingQuestion)
            && isset($existingQuestion['reveal_correct_after_two_incorrect_attempts'])
            && is_bool($existingQuestion['reveal_correct_after_two_incorrect_attempts'])) {
            $revealAfterTwoIncorrect = $existingQuestion['reveal_correct_after_two_incorrect_attempts'];
        }

        return [
            'id' => $id,
            'question_type' => $expectedType,
            'prompt_html' => $promptHtml,
            'points_possible' => (float) $pointsPossible,
            'reveal_correct_after_two_incorrect_attempts' => $revealAfterTwoIncorrect,
        ];
    }

    private function normalizeOptions(array $options, int $min = 2, int $max = 8): ?array
    {
        if (count($options) < $min || count($options) > $max) {
            return null;
        }

        $ids = [];
        $normalized = [];
        foreach ($options as $idx => $option) {
            if (!is_array($option)) {
                return null;
            }
            $id = $option['id'] ?? ('opt_' . ($idx + 1));
            $text = $option['text'] ?? null;
            if (!is_string($id) || trim($id) === '' || isset($ids[$id])) {
                return null;
            }
            if (!is_string($text) || trim($text) === '') {
                return null;
            }
            $ids[$id] = true;
            $normalized[] = ['id' => $id, 'text' => $text];
        }

        return $normalized;
    }

    private function normalizeQuestionMcSingle(array $q, ?array $existingQuestion = null): ?array
    {
        $base = $this->normalizeQuestionBase($q, self::QUESTION_TYPE_MC_SINGLE, $existingQuestion);
        if (!$base) {
            return null;
        }

        $options = $q['options'] ?? null;
        if (!is_array($options)) {
            return null;
        }
        $options = $this->normalizeOptions($options);
        if (!$options) {
            return null;
        }

        $correct = $q['correct_option_id'] ?? null;
        $optionIds = array_column($options, 'id');
        if (!is_string($correct) || !in_array($correct, $optionIds, true)) {
            return null;
        }

        $shuffle = $q['shuffle_options'] ?? false;
        if (!is_bool($shuffle)) {
            $shuffle = false;
        }

        return array_merge($base, [
            'options' => $options,
            'shuffle_options' => $shuffle,
            'correct_option_id' => $correct,
        ]);
    }

    private function normalizeQuestionMcMultiple(array $q, ?array $existingQuestion = null): ?array
    {
        $base = $this->normalizeQuestionBase($q, self::QUESTION_TYPE_MC_MULTIPLE, $existingQuestion);
        if (!$base) {
            return null;
        }

        $options = $q['options'] ?? null;
        if (!is_array($options)) {
            return null;
        }
        $options = $this->normalizeOptions($options);
        if (!$options) {
            return null;
        }

        $correct = $q['correct_option_ids'] ?? null;
        if (!is_array($correct) || count($correct) < 1) {
            return null;
        }

        $optionIds = array_flip(array_column($options, 'id'));
        $seen = [];
        $normalizedCorrect = [];
        foreach ($correct as $cid) {
            if (!is_string($cid) || !isset($optionIds[$cid]) || isset($seen[$cid])) {
                return null;
            }
            $seen[$cid] = true;
            $normalizedCorrect[] = $cid;
        }

        $shuffle = $q['shuffle_options'] ?? false;
        if (!is_bool($shuffle)) {
            $shuffle = false;
        }

        return array_merge($base, [
            'options' => $options,
            'shuffle_options' => $shuffle,
            'correct_option_ids' => $normalizedCorrect,
        ]);
    }

    private function normalizeQuestionMatching(array $q, ?array $existingQuestion = null): ?array
    {
        $base = $this->normalizeQuestionBase($q, self::QUESTION_TYPE_MATCHING, $existingQuestion);
        if (!$base) {
            return null;
        }

        $prompts = $q['prompts'] ?? null;
        $choices = $q['choices'] ?? null;
        $matches = $q['correct_matches'] ?? null;

        if (!is_array($prompts) || !is_array($choices) || !is_array($matches)) {
            return null;
        }

        $prompts = $this->normalizeOptions($prompts);
        $choices = $this->normalizeOptions($choices);
        if (!$prompts || !$choices) {
            return null;
        }

        if (count($matches) !== count($prompts)) {
            return null;
        }

        $promptIds = array_flip(array_column($prompts, 'id'));
        $choiceIds = array_flip(array_column($choices, 'id'));
        $usedPrompts = [];
        $usedChoices = [];
        $normalizedMatches = [];

        foreach ($matches as $m) {
            if (!is_array($m)) {
                return null;
            }
            $promptId = $m['prompt_id'] ?? null;
            $choiceId = $m['choice_id'] ?? null;
            if (!is_string($promptId) || !isset($promptIds[$promptId]) || isset($usedPrompts[$promptId])) {
                return null;
            }
            if (!is_string($choiceId) || !isset($choiceIds[$choiceId]) || isset($usedChoices[$choiceId])) {
                return null;
            }
            $usedPrompts[$promptId] = true;
            $usedChoices[$choiceId] = true;
            $normalizedMatches[] = ['prompt_id' => $promptId, 'choice_id' => $choiceId];
        }

        $shuffle = $q['shuffle_options'] ?? true;
        if (!is_bool($shuffle)) {
            $shuffle = true;
        }

        return array_merge($base, [
            'prompts' => $prompts,
            'choices' => $choices,
            'correct_matches' => $normalizedMatches,
            'shuffle_options' => $shuffle,
        ]);
    }

    private function normalizeQuestionFillInBlank(array $q, ?array $existingQuestion = null): ?array
    {
        $base = $this->normalizeQuestionBase($q, self::QUESTION_TYPE_FILL_IN_BLANK, $existingQuestion);
        if (!$base) {
            return null;
        }

        $blanks = $q['blanks'] ?? null;
        if (!is_array($blanks) || count($blanks) < 1 || count($blanks) > 8) {
            return null;
        }

        $normalized = [];
        $seen = [];
        foreach ($blanks as $blank) {
            if (!is_array($blank)) {
                return null;
            }
            $id = $blank['id'] ?? null;
            $answers = $blank['acceptable_answers'] ?? null;
            if (!is_string($id) || trim($id) === '' || isset($seen[$id])) {
                return null;
            }
            if (!is_array($answers) || count($answers) < 1) {
                return null;
            }

            $normAnswers = [];
            foreach ($answers as $a) {
                if (!is_string($a) || trim($a) === '') {
                    return null;
                }
                $normAnswers[] = $a;
            }

            if (!str_contains($base['prompt_html'], '[[' . $id . ']]')) {
                return null;
            }

            $seen[$id] = true;
            $normalized[] = ['id' => $id, 'acceptable_answers' => $normAnswers];
        }

        return array_merge($base, [
            'blanks' => $normalized,
            'shuffle_options' => false,
        ]);
    }

    private function normalizeQuestionOrdering(array $q, ?array $existingQuestion = null): ?array
    {
        $base = $this->normalizeQuestionBase($q, self::QUESTION_TYPE_ORDERING, $existingQuestion);
        if (!$base) {
            return null;
        }

        $items = $q['items'] ?? null;
        $order = $q['correct_order'] ?? null;
        if (!is_array($items) || !is_array($order)) {
            return null;
        }

        $items = $this->normalizeOptions($items);
        if (!$items || count($order) !== count($items)) {
            return null;
        }

        $ids = array_flip(array_column($items, 'id'));
        $seen = [];
        $normalizedOrder = [];
        foreach ($order as $id) {
            if (!is_string($id) || !isset($ids[$id]) || isset($seen[$id])) {
                return null;
            }
            $seen[$id] = true;
            $normalizedOrder[] = $id;
        }

        return array_merge($base, [
            'items' => $items,
            'correct_order' => $normalizedOrder,
            'shuffle_options' => true,
        ]);
    }

    private function normalizeQuestionNumeric(array $q, ?array $existingQuestion = null): ?array
    {
        $base = $this->normalizeQuestionBase($q, self::QUESTION_TYPE_NUMERIC, $existingQuestion);
        if (!$base) {
            return null;
        }

        $answerMode = $q['answer_mode'] ?? null;
        if (!is_string($answerMode) || !in_array($answerMode, ['exact', 'tolerance'], true)) {
            return null;
        }

        $normalized = array_merge($base, [
            'answer_mode' => $answerMode,
            'shuffle_options' => false,
        ]);

        if ($answerMode === 'exact') {
            if (!is_numeric($q['correct_value'] ?? null)) {
                return null;
            }
            $normalized['correct_value'] = (float) $q['correct_value'];
            return $normalized;
        }

        $hasTargetTolerance = is_numeric($q['target_value'] ?? null) && is_numeric($q['tolerance'] ?? null);
        $hasRange = is_numeric($q['min_value'] ?? null) && is_numeric($q['max_value'] ?? null);

        if (!$hasTargetTolerance && !$hasRange) {
            return null;
        }

        if ($hasTargetTolerance) {
            $tolerance = (float) $q['tolerance'];
            if ($tolerance < 0) {
                return null;
            }
            $normalized['target_value'] = (float) $q['target_value'];
            $normalized['tolerance'] = $tolerance;
            return $normalized;
        }

        $min = (float) $q['min_value'];
        $max = (float) $q['max_value'];
        if ($min > $max) {
            return null;
        }

        $normalized['min_value'] = $min;
        $normalized['max_value'] = $max;

        return $normalized;
    }

    private function normalizeQuestionImageHotspotSingle(array $q, ?array $existingQuestion = null): ?array
    {
        $base = $this->normalizeQuestionBase($q, self::QUESTION_TYPE_IMAGE_HOTSPOT_SINGLE, $existingQuestion);
        if (!$base) {
            return null;
        }

        $image = $q['image'] ?? null;
        if (!is_array($image)) {
            return null;
        }

        $assetId = $image['asset_id'] ?? null;
        $url = $image['url'] ?? null;
        if (!is_string($assetId) || trim($assetId) === '' || !is_string($url) || trim($url) === '') {
            return null;
        }

        $hotspots = $q['hotspots'] ?? null;
        if (!is_array($hotspots) || count($hotspots) < 1 || count($hotspots) > 12) {
            return null;
        }

        $normalizedHotspots = [];
        $seenIds = [];
        foreach ($hotspots as $idx => $hotspot) {
            if (!is_array($hotspot)) {
                return null;
            }
            $id = $hotspot['id'] ?? ('hs_' . ($idx + 1));
            if (!is_string($id) || trim($id) === '' || isset($seenIds[$id])) {
                return null;
            }
            if (!is_numeric($hotspot['x'] ?? null) || !is_numeric($hotspot['y'] ?? null) || !is_numeric($hotspot['w'] ?? null) || !is_numeric($hotspot['h'] ?? null)) {
                return null;
            }

            $x = max(0.0, min(1.0, (float) $hotspot['x']));
            $y = max(0.0, min(1.0, (float) $hotspot['y']));
            $w = (float) $hotspot['w'];
            $h = (float) $hotspot['h'];
            if ($w <= 0 || $h <= 0 || ($x + $w) > 1.0 || ($y + $h) > 1.0) {
                return null;
            }

            $normalizedHotspots[] = [
                'id' => $id,
                'x' => round($x, 6),
                'y' => round($y, 6),
                'w' => round($w, 6),
                'h' => round($h, 6),
                'label' => is_string($hotspot['label'] ?? null) ? trim($hotspot['label']) : '',
            ];
            $seenIds[$id] = true;
        }

        $correctId = $q['correct_hotspot_id'] ?? null;
        if (!is_string($correctId) || !isset($seenIds[$correctId])) {
            return null;
        }

        return array_merge($base, [
            'image' => [
                'asset_id' => $assetId,
                'url' => trim($url),
                'alt' => is_string($image['alt'] ?? null) ? trim($image['alt']) : '',
                'width' => is_numeric($image['width'] ?? null) ? (int) $image['width'] : null,
                'height' => is_numeric($image['height'] ?? null) ? (int) $image['height'] : null,
            ],
            'hotspots' => $normalizedHotspots,
            'correct_hotspot_id' => $correctId,
            'shuffle_options' => false,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getAvailableImageAssets(?object $app): array
    {
        if (!$app || !isset($app->id)) {
            return [];
        }

        return DB::table('app_assets')
            ->where('app_id', (int) $app->id)
            ->where('kind', 'image')
            ->orderBy('created_at')
            ->get()
            ->map(function ($row) {
                return [
                    'asset_id' => (string) $row->id,
                    'label' => (string) ($row->label ?? ''),
                    'alt_text' => (string) ($row->alt_text ?? ''),
                    'url' => (string) $row->url_optimized,
                    'disk' => (string) $row->disk,
                    'path_optimized' => (string) $row->path_optimized,
                    'mime' => (string) ($row->mime_optimized ?? 'image/webp'),
                    'width' => (int) $row->width,
                    'height' => (int) $row->height,
                    'rights_basis' => (string) $row->rights_basis,
                    'cc_license' => $row->cc_license ? (string) $row->cc_license : null,
                    'copyright_holder' => $row->copyright_holder ? (string) $row->copyright_holder : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param array<int, array<string, mixed>> $assets
     */
    private function buildAvailableAssetsContext(array $assets): string
    {
        if ($assets === []) {
            return "AVAILABLE_IMAGE_ASSETS:\n- none";
        }

        $json = json_encode($assets, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return "AVAILABLE_IMAGE_ASSETS:\n- none";
        }

        return <<<TXT
AVAILABLE_IMAGE_ASSETS:
{$json}

Rules for assets:
- Use only these asset URLs for generated image references.
- For hotspot questions, set image.asset_id and image.url from this list.
TXT;
    }

    private function promptReferencesImage(string $prompt): bool
    {
        $p = strtolower($prompt);
        $keywords = [
            'image', 'images', 'img', 'pic', 'pics', 'picture', 'pictures',
            'photo', 'photos', 'figure', 'fig', 'diagram', 'schematic',
            'chart', 'graph', 'plot', 'illustration', 'slide', 'slides',
            'screenshot', 'scan', 'xray', 'microscopy',
        ];

        foreach ($keywords as $keyword) {
            if (preg_match('/\\b' . preg_quote($keyword, '/') . '\\b/u', $p) === 1) {
                return true;
            }
        }

        $phrases = [
            'based on this image',
            'based on the image',
            'from the picture',
            'in the diagram',
            'shown above',
            'shown below',
        ];
        foreach ($phrases as $phrase) {
            if (str_contains($p, $phrase)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $assets
     * @param string[] $explicitAssetIds
     * @return array<int, array<string, mixed>>
     */
    private function selectVisionAssets(array $assets, string $prompt, array $explicitAssetIds = []): array
    {
        $max = max(1, (int) env('OPENAI_VISION_MAX_IMAGES', 10));
        if ($assets === []) {
            return [];
        }

        $explicit = array_values(array_filter(array_map('strval', $explicitAssetIds), fn ($v) => trim($v) !== ''));
        $promptLower = strtolower($prompt);
        $ranked = $assets;

        usort($ranked, function (array $a, array $b) use ($explicit, $promptLower) {
            $aId = (string) ($a['asset_id'] ?? '');
            $bId = (string) ($b['asset_id'] ?? '');
            $aScore = 0;
            $bScore = 0;
            if ($explicit !== []) {
                $aPos = array_search($aId, $explicit, true);
                $bPos = array_search($bId, $explicit, true);
                $aScore += ($aPos === false) ? -1000 : (500 - $aPos);
                $bScore += ($bPos === false) ? -1000 : (500 - $bPos);
            }

            $aText = strtolower(trim(((string) ($a['label'] ?? '')) . ' ' . ((string) ($a['alt_text'] ?? ''))));
            $bText = strtolower(trim(((string) ($b['label'] ?? '')) . ' ' . ((string) ($b['alt_text'] ?? ''))));
            if ($aText !== '' && str_contains($promptLower, $aText)) {
                $aScore += 200;
            }
            if ($bText !== '' && str_contains($promptLower, $bText)) {
                $bScore += 200;
            }

            return $bScore <=> $aScore;
        });

        return array_slice($ranked, 0, $max);
    }

    /**
     * @param array<int, array<string, mixed>> $selectedAssets
     * @return array<int, array<string, mixed>>
     */
    private function buildVisionInputs(array $selectedAssets): array
    {
        $inputs = [];
        foreach ($selectedAssets as $asset) {
            $disk = (string) ($asset['disk'] ?? '');
            $path = (string) ($asset['path_optimized'] ?? '');
            $mime = (string) ($asset['mime'] ?? 'image/webp');
            if ($disk === '' || $path === '') {
                continue;
            }
            try {
                $binary = Storage::disk($disk)->get($path);
            } catch (\Throwable $e) {
                continue;
            }
            if (!is_string($binary) || $binary === '') {
                continue;
            }
            $dataUrl = 'data:' . $mime . ';base64,' . base64_encode($binary);
            $inputs[] = [
                'type' => 'image_url',
                'image_url' => ['url' => $dataUrl],
            ];
        }
        return $inputs;
    }

    /**
     * @param array<int, array<string, mixed>> $selectedAssets
     */
    private function runVisionAnalysis(string $prompt, string $generationMode, ?string $questionType, array $selectedAssets): ?string
    {
        $visionModel = (string) env('OPENAI_MODEL_VISION_FAST', env('OPENAI_MODEL', 'gpt-4.1-mini'));
        $visionInputs = $this->buildVisionInputs($selectedAssets);
        if ($visionInputs === []) {
            return null;
        }

        $assetSummary = json_encode(array_map(function ($a) {
            return [
                'asset_id' => $a['asset_id'] ?? null,
                'label' => $a['label'] ?? null,
                'alt_text' => $a['alt_text'] ?? null,
                'width' => $a['width'] ?? null,
                'height' => $a['height'] ?? null,
            ];
        }, $selectedAssets), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $system = <<<TXT
You are analyzing educational images for content generation.
Return ONLY JSON with shape:
{
  "summary": string,
  "visual_facts": [string],
  "hotspot_candidates": [{"label": string, "x": number, "y": number, "w": number, "h": number}]
}
- Coordinates must be normalized in [0..1].
- Keep facts concise and objective.
TXT;
        $text = <<<TXT
Prompt: {$prompt}
Generation mode: {$generationMode}
Question type: {$questionType}
Selected assets:
{$assetSummary}
TXT;

        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => array_merge([['type' => 'text', 'text' => $text]], $visionInputs)],
        ];

        $result = $this->callLLM($messages, $visionModel);
        if ($result['error'] || !is_array($result['package'])) {
            return null;
        }
        $json = json_encode($result['package'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : null;
    }

    private function visionTwoPassEnabled(): bool
    {
        return (bool) env('OPENAI_VISION_TWO_PASS', false);
    }

    private function resolveGenerationModel(bool $visionEnabledForRequest): string
    {
        $defaultTextModel = (string) env('OPENAI_MODEL_TEXT_QUALITY', env('OPENAI_MODEL', 'gpt-4.1-mini'));
        if (!$visionEnabledForRequest) {
            return $defaultTextModel;
        }

        if ($this->visionTwoPassEnabled()) {
            return $defaultTextModel;
        }

        return (string) env('OPENAI_MODEL_VISION_FAST', env('OPENAI_MODEL', 'gpt-4.1-mini'));
    }

    private function mapResourceLink(?int $appId, Request $request): void
    {
        if (!$appId) {
            return;
        }

        $auth = $request->attributes->get('auth');
        $lti = is_array($auth) ? ($auth['lti'] ?? null) : null;
        $issuer = is_array($lti) ? ($lti['issuer'] ?? null) : null;
        $deploymentId = is_array($lti) ? ($lti['deployment_id'] ?? null) : null;
        $resourceLinkId = is_array($lti) ? ($lti['resource_link_id'] ?? null) : null;

        if (is_string($issuer) && $issuer !== '' &&
            is_string($deploymentId) && $deploymentId !== '' &&
            is_string($resourceLinkId) && $resourceLinkId !== '') {
            $now = now();
            DB::table('resource_links')->updateOrInsert(
                [
                    'issuer' => $issuer,
                    'deployment_id' => $deploymentId,
                    'resource_link_id' => $resourceLinkId,
                ],
                [
                    'app_id' => $appId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            DB::table('apps')->where('id', $appId)->update([
                'lifecycle_status' => 'inserted',
                'inserted_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function generateOpenInteractionPackage(
        Request $request,
        ?object $existingApp,
        array $availableAssets = [],
        ?string $modelOverride = null,
        array $visionInputs = [],
        ?string $visionAnalysisText = null
    ): array
    {
        $assetContext = $this->buildAvailableAssetsContext($availableAssets);
        $analysisChunk = ($visionAnalysisText && trim($visionAnalysisText) !== '')
            ? "\nVISION_ANALYSIS:\n{$visionAnalysisText}\nUse it when relevant."
            : '';
        if ($existingApp) {
            $system = <<<SYS
You are revising an existing learning application in a sandboxed iframe.

CURRENT APPLICATION:
Title: {$existingApp->title}

HTML:
{$existingApp->html}

CSS:
{$existingApp->css}

JavaScript:
{$existingApp->js}

INSTRUCTIONS:
- User wants specific changes to this application
- PRESERVE overall structure unless explicitly asked to change it
- Return COMPLETE revised application (not just changes)
- Output ONLY valid JSON with title, html, css, js
- Follow all sandbox rules (no forms, fetch, external libs)
- Mount into element with id="app"
- Use window.sdk.getState(), setState(), notify() where appropriate
- Prefer AVAILABLE_IMAGE_ASSETS URLs when rendering images
SYS;

            $user = <<<USR
User's revision request: {$request->prompt}

{$assetContext}
{$analysisChunk}

Return complete revised JSON only.
USR;
        } else {
            $system = <<<SYS
You are generating a small learning application to run inside a sandboxed iframe.

Rules:
- Output ONLY valid JSON.
- Do NOT wrap the JSON in Markdown code fences.
- Do NOT include any explanation or text outside the JSON object.
- JSON must contain: title, html, css, js.
- Use plain HTML, CSS, and vanilla JavaScript.
- Do NOT use external libraries.
- Do NOT use fetch or network access.
- Do NOT rely on native form submission.
- DO NOT use <form>.
- Never set a form action or submit data via the browser.
- Treat buttons as JavaScript triggers, not HTML submit actions.
- Mount into an element with id="app".
- Use window.sdk.getState(), setState(), notify() where appropriate.
- Prefer AVAILABLE_IMAGE_ASSETS URLs when rendering images.
SYS;

            $user = <<<USR
{$request->prompt}

{$assetContext}
{$analysisChunk}

Return JSON only.
USR;
        }

        Log::info('Open interaction generation initiated', [
            'prompt_length' => strlen($request->prompt),
            'model' => env('OPENAI_MODEL', 'gpt-4.1-mini'),
            'temperature' => env('OPENAI_TEMPERATURE', 0.3),
            'is_revision' => (bool) $existingApp,
        ]);

        $userContent = $visionInputs !== []
            ? array_merge([['type' => 'text', 'text' => $user]], $visionInputs)
            : $user;

        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $userContent],
        ];

        $result = $this->callLLM($messages, $modelOverride);
        if ($result['error']) {
            return ['error' => $result['error'], 'status' => 500];
        }

        $package = $result['package'];
        $violations = $this->validatePackage($package);
        $didAutoRetry = false;

        if (!empty($violations)) {
            $violationsList = implode("\n- ", $violations);
            $correctionMessage = <<<MSG
Your previous output violated sandbox rules:
- {$violationsList}

Please fix these issues and return the corrected JSON. Remember:
- Do NOT use <form> tags - use buttons with JavaScript click handlers instead
- Do NOT use fetch() or XMLHttpRequest
- Do NOT use localStorage/sessionStorage (use sdk.getState/setState instead)
- Do NOT use window.location or document.cookie

Return the complete fixed JSON only.
MSG;

            $messages[] = ['role' => 'assistant', 'content' => $result['raw']];
            $messages[] = ['role' => 'user', 'content' => $correctionMessage];

            $retryResult = $this->callLLM($messages);
            if ($retryResult['error']) {
                return ['error' => 'Generated app violates sandbox rules (auto-retry also failed)', 'status' => 422, 'violations' => $violations];
            }

            $retryPackage = $retryResult['package'];
            $retryViolations = $this->validatePackage($retryPackage);
            if (!empty($retryViolations)) {
                return ['error' => 'Generated app violates sandbox rules', 'status' => 422, 'violations' => $retryViolations, 'auto_retry_attempted' => true];
            }

            $package = $retryPackage;
            $didAutoRetry = true;
        }

        return [
            'status' => 200,
            'kind' => self::KIND_OPEN_INTERACTION,
            'package' => $package,
            'did_auto_retry' => $didAutoRetry,
        ];
    }

    public function generate(Request $request)
    {
        $request->validate([
            'prompt' => 'required|string|max:10000',
            'app_id' => 'nullable|integer|exists:apps,id',
            'preview' => 'nullable|boolean',
            'generation_mode' => 'required|string|in:' . self::KIND_STRUCTURED_QUESTION_SET . ',' . self::KIND_OPEN_INTERACTION,
            'question_type' => 'nullable|string',
            'start_fresh' => 'nullable|boolean',
            'use_vision' => 'nullable|boolean',
            'vision_mode' => 'nullable|string|in:auto,force,off',
            'asset_ids' => 'nullable|array',
            'asset_ids.*' => 'string',
        ]);

        $preview = (bool) $request->input('preview', false);
        $generationMode = (string) $request->input('generation_mode');
        $questionType = $request->input('question_type');
        $startFresh = (bool) $request->input('start_fresh', false);
        $visionMode = (string) $request->input('vision_mode', 'auto');
        if ($request->has('use_vision') && !$request->has('vision_mode')) {
            $visionMode = ((bool) $request->boolean('use_vision')) ? 'force' : 'off';
        }
        $explicitAssetIds = is_array($request->input('asset_ids')) ? $request->input('asset_ids') : [];
        $existingApp = null;

        if ($request->has('app_id')) {
            $existingApp = DB::table('apps')->where('id', $request->app_id)->first();
            if (!$existingApp) {
                return response()->json(['error' => 'App not found'], 404);
            }
        }

        // When starting fresh, don't pass existing content to the LLM — generate from scratch
        // but still save to the existing app record so the LTI custom app_id remains valid.
        $existingAppForLlm = ($startFresh) ? null : $existingApp;

        $existingStructured = null;
        if ($existingAppForLlm && ($existingAppForLlm->kind ?? self::KIND_OPEN_INTERACTION) === self::KIND_STRUCTURED_QUESTION_SET) {
            $decoded = json_decode((string) ($existingAppForLlm->structured_json ?? ''), true);
            if (is_array($decoded)) {
                $existingStructured = $decoded;
            }
        }
        $availableAssets = $this->getAvailableImageAssets($existingApp);
        $visionEnabled = (bool) env('OPENAI_VISION_ENABLED', true);
        $shouldUseVision = false;
        if ($visionEnabled) {
            if ($visionMode === 'force') {
                $shouldUseVision = true;
            } elseif ($visionMode === 'off') {
                $shouldUseVision = false;
            } else {
                $shouldUseVision = $questionType === self::QUESTION_TYPE_IMAGE_HOTSPOT_SINGLE
                    || $this->promptReferencesImage((string) $request->prompt)
                    || $explicitAssetIds !== [];
            }
        }

        $selectedVisionAssets = $shouldUseVision
            ? $this->selectVisionAssets($availableAssets, (string) $request->prompt, $explicitAssetIds)
            : [];

        if ($visionMode === 'force' && $selectedVisionAssets === []) {
            return response()->json(['error' => 'vision_mode=force requested but no usable app images were found.'], 422);
        }

        $visionInputs = ($shouldUseVision && !$this->visionTwoPassEnabled())
            ? $this->buildVisionInputs($selectedVisionAssets)
            : [];
        $visionAnalysis = ($shouldUseVision && $this->visionTwoPassEnabled())
            ? $this->runVisionAnalysis((string) $request->prompt, $generationMode, is_string($questionType) ? $questionType : null, $selectedVisionAssets)
            : null;

        $finalModel = $this->resolveGenerationModel($shouldUseVision);
        $generationPath = $shouldUseVision
            ? ($this->visionTwoPassEnabled() ? 'vision_two_pass' : 'vision_direct')
            : 'text';
        $warnings = [];
        if ($shouldUseVision && $this->visionTwoPassEnabled() && $visionAnalysis === null) {
            $warnings[] = 'Vision analysis failed; proceeding without image-derived facts.';
        }
        if ($shouldUseVision && !$this->visionTwoPassEnabled() && $visionInputs === []) {
            $warnings[] = 'No readable image bytes found for vision inputs; proceeding text-only.';
            $generationPath = 'text';
            $finalModel = (string) env('OPENAI_MODEL_TEXT_QUALITY', env('OPENAI_MODEL', 'gpt-4.1-mini'));
        }

        if ($generationMode === self::KIND_STRUCTURED_QUESTION_SET) {
            if (!is_string($questionType) || !in_array($questionType, self::STRUCTURED_TYPES, true)) {
                return response()->json([
                    'error' => 'question_type is required and must be one of: ' . implode(', ', self::STRUCTURED_TYPES),
                ], 422);
            }
        } else {
            if ($questionType !== null && $questionType !== '') {
                return response()->json([
                    'error' => 'question_type must be omitted when generation_mode is open_interaction',
                ], 422);
            }
        }

        $existingMode = $existingApp ? (string) ($existingApp->kind ?? self::KIND_OPEN_INTERACTION) : null;
        $existingType = $existingStructured['questions'][0]['question_type'] ?? null;

        Log::info('Generation mode requested', [
            'requested_mode' => $generationMode,
            'requested_question_type' => $questionType,
            'existing_mode' => $existingMode,
            'existing_question_type' => $existingType,
            'has_existing_app' => (bool) $existingApp,
            'preview' => $preview,
        ]);

        if ($generationMode === self::KIND_STRUCTURED_QUESTION_SET) {
            $structured = $this->generateStructuredQuestionSet(
                $request->prompt,
                1.0,
                $questionType,
                $existingStructured,
                $availableAssets,
                $finalModel,
                $visionInputs,
                $visionAnalysis
            );

            if ($structured === null) {
                return response()->json([
                    'error' => 'Unable to generate a valid structured question set for the requested question_type.',
                ], 422);
            }

            $appId = $existingApp ? (int) $existingApp->id : null;

            if (!$preview) {
                if ($existingApp) {
                    DB::table('apps')->where('id', $existingApp->id)->update([
                        'title' => $structured['title'],
                        'kind' => self::KIND_STRUCTURED_QUESTION_SET,
                        'html' => null,
                        'css' => null,
                        'js' => null,
                        'structured_json' => json_encode($structured),
                        'updated_at' => now(),
                    ]);
                    $appId = (int) $existingApp->id;
                } else {
                    $appId = DB::table('apps')->insertGetId([
                        'title' => $structured['title'],
                        'kind' => self::KIND_STRUCTURED_QUESTION_SET,
                        'html' => null,
                        'css' => null,
                        'js' => null,
                        'structured_json' => json_encode($structured),
                        'lifecycle_status' => 'draft_uninserted',
                        'inserted_at' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            $this->mapResourceLink($appId, $request);
            $appRow = $appId ? DB::table('apps')->where('id', $appId)->first() : null;

            return response()->json([
                'kind' => self::KIND_STRUCTURED_QUESTION_SET,
                'schema_version' => $structured['schema_version'],
                'id' => $appId,
                'title' => $structured['title'],
                'questions' => $structured['questions'],
                'meta' => $structured['meta'],
                'lifecycle_status' => (string) ($appRow->lifecycle_status ?? 'inserted'),
                'inserted_at' => $appRow->inserted_at ?? null,
                'generation_path' => $generationPath,
                'used_asset_ids' => array_values(array_map(fn ($a) => (string) ($a['asset_id'] ?? ''), $selectedVisionAssets)),
                'warnings' => $warnings,
            ]);
        }

        // open_interaction path
        $openResult = $this->generateOpenInteractionPackage(
            $request,
            $existingAppForLlm,
            $availableAssets,
            $finalModel,
            $visionInputs,
            $visionAnalysis
        );
        if (($openResult['status'] ?? 500) !== 200) {
            return response()->json($openResult, $openResult['status'] ?? 500);
        }

        $package = $openResult['package'];
        $appId = $existingApp ? (int) $existingApp->id : null;

        if (!$preview) {
            if ($existingApp) {
                DB::table('apps')->where('id', $existingApp->id)->update([
                    'title' => $package['title'] ?? $existingApp->title,
                    'kind' => self::KIND_OPEN_INTERACTION,
                    'html' => $package['html'] ?? '',
                    'css' => $package['css'] ?? '',
                    'js' => $package['js'] ?? '',
                    'structured_json' => null,
                    'updated_at' => now(),
                ]);
                $appId = (int) $existingApp->id;
            } else {
                $appId = DB::table('apps')->insertGetId([
                    'title' => $package['title'] ?? 'Generated app',
                    'kind' => self::KIND_OPEN_INTERACTION,
                    'html' => $package['html'] ?? "<div id='app'></div>",
                    'css' => $package['css'] ?? '',
                    'js' => $package['js'] ?? '',
                    'structured_json' => null,
                    'lifecycle_status' => 'draft_uninserted',
                    'inserted_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $this->mapResourceLink($appId, $request);
        $appRow = $appId ? DB::table('apps')->where('id', $appId)->first() : null;

        $response = [
            'kind' => self::KIND_OPEN_INTERACTION,
            'id' => $appId,
            'title' => $package['title'] ?? 'Generated app',
            'html' => $package['html'] ?? "<div id='app'></div>",
            'css' => $package['css'] ?? '',
            'js' => $package['js'] ?? '',
            'lifecycle_status' => (string) ($appRow->lifecycle_status ?? 'inserted'),
            'inserted_at' => $appRow->inserted_at ?? null,
            'generation_path' => $generationPath,
            'used_asset_ids' => array_values(array_map(fn ($a) => (string) ($a['asset_id'] ?? ''), $selectedVisionAssets)),
            'warnings' => $warnings,
        ];

        if (!empty($openResult['did_auto_retry'])) {
            $response['auto_retry'] = true;
        }

        return response()->json($response);
    }
}
