<?php

namespace App\Services\Generation;

use App\Services\StructuredHtmlSanitizer;

class StructuredGenerationService
{
    private const KIND_STRUCTURED_QUESTION_SET = 'structured_question_set';
    private const SCHEMA_VERSION = '2026-02-18';

    public const QUESTION_TYPE_MC_SINGLE = 'multiple_choice_single_answer';
    public const QUESTION_TYPE_MC_MULTIPLE = 'multiple_choice_multiple_answer';
    public const QUESTION_TYPE_MATCHING = 'matching';
    public const QUESTION_TYPE_FILL_IN_BLANK = 'fill_in_blank';
    public const QUESTION_TYPE_ORDERING = 'ordering';
    public const QUESTION_TYPE_NUMERIC = 'numeric';
    public const QUESTION_TYPE_IMAGE_HOTSPOT_SINGLE = 'image_hotspot_single';

    public const STRUCTURED_TYPES = [
        self::QUESTION_TYPE_MC_SINGLE,
        self::QUESTION_TYPE_MC_MULTIPLE,
        self::QUESTION_TYPE_MATCHING,
        self::QUESTION_TYPE_FILL_IN_BLANK,
        self::QUESTION_TYPE_ORDERING,
        self::QUESTION_TYPE_NUMERIC,
        self::QUESTION_TYPE_IMAGE_HOTSPOT_SINGLE,
    ];

    public function __construct(private readonly LlmGenerationService $llm)
    {
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

    public function generateStructuredQuestionSet(
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

        $result = $this->llm->callLLM([
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

    public function normalizeStructuredQuestionSetByType(
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

    private function buildAvailableAssetsContext(array $assets): string
    {
        if (count($assets) === 0) {
            return 'AVAILABLE_IMAGE_ASSETS:\n- none';
        }

        $lines = ['AVAILABLE_IMAGE_ASSETS:'];
        foreach ($assets as $asset) {
            $assetId = (string) ($asset['asset_id'] ?? '');
            $label = (string) ($asset['label'] ?? '');
            $url = (string) ($asset['url'] ?? '');
            if ($assetId === '' || $url === '') {
                continue;
            }
            $labelSuffix = $label !== '' ? " ({$label})" : '';
            $lines[] = "- {$assetId}{$labelSuffix}: {$url}";
        }

        if (count($lines) === 1) {
            $lines[] = '- none';
        }

        return implode("\n", $lines);
    }
}
