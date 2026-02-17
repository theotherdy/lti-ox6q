<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AppController extends Controller
{
    private const STRUCTURED_TYPES = [
        'multiple_choice_single_answer',
        'multiple_choice_multiple_answer',
        'matching',
        'fill_in_blank',
        'ordering',
        'numeric',
    ];

    public function package(Request $request, $appId)
    {
        if (!ctype_digit((string) $appId)) {
            return response()->json(['error' => 'Invalid appId (must be numeric).'], 400);
        }
        $appId = (int) $appId;

        $row = DB::table('apps')->where('id', $appId)->first();

        if (!$row) {
            return response()->json(['error' => 'App not found.'], 404);
        }

        $kind = $row->kind ?? 'open_interaction';
        if ($kind === 'structured_question_set') {
            $structured = json_decode((string) ($row->structured_json ?? ''), true);
            if (!is_array($structured)) {
                return response()->json(['error' => 'Stored structured question payload is invalid.'], 500);
            }

            return response()->json([
                'kind' => 'structured_question_set',
                'id' => $row->id,
                'schema_version' => $structured['schema_version'] ?? null,
                'title' => $structured['title'] ?? $row->title,
                'questions' => $structured['questions'] ?? [],
                'meta' => $structured['meta'] ?? [],
            ]);
        }

        return response()->json([
            'kind' => 'open_interaction',
            'id' => $row->id,
            'title' => $row->title,
            'html' => $row->html,
            'css' => $row->css,
            'js' => $row->js,
        ]);
    }

    public function getState(Request $request, $appId)
    {
        if (!ctype_digit((string) $appId)) {
            return response()->json(['error' => 'Invalid appId (must be numeric).'], 400);
        }
        $appId = (int) $appId;

        $sub = $request->attributes->get('auth_sub');
        $ltiUserId = $this->getLtiUserId($sub);

        $row = DB::table('app_states')
            ->where('app_id', $appId)
            ->where('lti_user_id', $ltiUserId)
            ->first();

        if (!$row) {
            return response()->json(['state' => null]);
        }

        $state = json_decode($row->state_json, true);
        return response()->json(['state' => $state]);
    }

    public function setState(Request $request, $appId)
    {
        if (!ctype_digit((string) $appId)) {
            return response()->json(['error' => 'Invalid appId (must be numeric).'], 400);
        }
        $appId = (int) $appId;

        $sub = $request->attributes->get('auth_sub');
        $ltiUserId = $this->getLtiUserId($sub);

        $request->validate([
            'state' => ['required'],
        ]);

        $stateJson = json_encode($request->input('state'));

        $exists = DB::table('app_states')
            ->where('app_id', $appId)
            ->where('lti_user_id', $ltiUserId)
            ->exists();

        if ($exists) {
            DB::table('app_states')
                ->where('app_id', $appId)
                ->where('lti_user_id', $ltiUserId)
                ->update([
                    'state_json' => $stateJson,
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('app_states')->insert([
                'app_id' => $appId,
                'lti_user_id' => $ltiUserId,
                'state_json' => $stateJson,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json(['ok' => true]);
    }

    public function clearMapping(Request $request)
    {
        $auth = $request->attributes->get('auth');
        $lti = is_array($auth) ? ($auth['lti'] ?? null) : null;
        $issuer = is_array($lti) ? ($lti['issuer'] ?? null) : null;
        $deploymentId = is_array($lti) ? ($lti['deployment_id'] ?? null) : null;
        $resourceLinkId = is_array($lti) ? ($lti['resource_link_id'] ?? null) : null;

        Log::debug('clearMapping inputs', [
            'issuer' => $issuer,
            'deployment_id' => $deploymentId,
            'resource_link_id' => $resourceLinkId,
            'has_auth' => is_array($auth),
            'has_lti' => is_array($lti),
        ]);

        if (!is_string($issuer) || $issuer === '' ||
            !is_string($deploymentId) || $deploymentId === '' ||
            !is_string($resourceLinkId) || $resourceLinkId === '') {
            return response()->json(['error' => 'Missing LTI context for mapping.'], 400);
        }

        $deleted = DB::table('resource_links')
            ->where('issuer', $issuer)
            ->where('deployment_id', $deploymentId)
            ->where('resource_link_id', $resourceLinkId)
            ->delete();

        Log::debug('clearMapping result', [
            'deleted' => $deleted,
        ]);

        return response()->json(['ok' => true, 'deleted' => $deleted]);
    }

    public function saveRevision(Request $request, $appId)
    {
        $kind = $request->input('kind', 'open_interaction');
        if (!is_string($kind) || !in_array($kind, ['open_interaction', 'structured_question_set'], true)) {
            return response()->json(['error' => 'Invalid revision kind'], 422);
        }

        // Verify app exists
        $app = DB::table('apps')->where('id', $appId)->first();
        if (!$app) {
            return response()->json(['error' => 'App not found'], 404);
        }

        if ($kind === 'structured_question_set') {
            $request->validate([
                'schema_version' => 'required|string',
                'title' => 'required|string',
                'questions' => 'required|array|size:1',
                'meta' => 'nullable|array',
            ]);

            $questions = $request->input('questions');
            $q = $questions[0] ?? null;
            if (!is_array($q) || !in_array(($q['question_type'] ?? null), self::STRUCTURED_TYPES, true)) {
                return response()->json(['error' => 'Unsupported structured question type'], 422);
            }
            $validationError = $this->validateStructuredQuestion($q);
            if ($validationError !== null) {
                return response()->json(['error' => $validationError], 422);
            }

            $payload = [
                'kind' => 'structured_question_set',
                'schema_version' => $request->input('schema_version'),
                'title' => $request->input('title'),
                'questions' => $questions,
                'meta' => $request->input('meta', []),
            ];

            DB::table('apps')->where('id', $appId)->update([
                'title' => $request->input('title'),
                'kind' => 'structured_question_set',
                'html' => null,
                'css' => null,
                'js' => null,
                'structured_json' => json_encode($payload),
                'updated_at' => now(),
            ]);
        } else {
            $request->validate([
                'title' => 'required|string',
                'html' => 'required|string',
                'css' => 'required|string',
                'js' => 'required|string',
            ]);

            $violations = $this->validatePackage([
                'title' => $request->title,
                'html' => $request->html,
                'css' => $request->css,
                'js' => $request->js,
            ]);

            if (!empty($violations)) {
                return response()->json([
                    'error' => 'Revision violates sandbox rules',
                    'violations' => $violations,
                ], 422);
            }

            DB::table('apps')->where('id', $appId)->update([
                'title' => $request->title,
                'kind' => 'open_interaction',
                'html' => $request->html,
                'css' => $request->css,
                'js' => $request->js,
                'structured_json' => null,
                'updated_at' => now(),
            ]);
        }

        return response()->json(['success' => true]);
    }

    private function validateStructuredQuestion(array $q): ?string
    {
        $type = $q['question_type'] ?? null;
        if (!is_string($type)) {
            return 'Missing question_type';
        }

        if (!is_string($q['id'] ?? null) || !is_string($q['prompt_html'] ?? null)) {
            return 'Structured question must include id and prompt_html';
        }
        if (!is_numeric($q['points_possible'] ?? null)) {
            return 'Structured question must include numeric points_possible';
        }

        $validateOptions = function ($options): ?array {
            if (!is_array($options) || count($options) < 2 || count($options) > 8) {
                return null;
            }
            $ids = [];
            foreach ($options as $option) {
                if (!is_array($option) || !is_string($option['id'] ?? null) || !is_string($option['text'] ?? null)) {
                    return null;
                }
                if (isset($ids[$option['id']])) {
                    return null;
                }
                $ids[$option['id']] = true;
            }
            return array_keys($ids);
        };

        if ($type === 'multiple_choice_single_answer') {
            $optionIds = $validateOptions($q['options'] ?? null);
            if (!$optionIds) {
                return 'multiple_choice_single_answer requires 2-8 valid options';
            }
            if (!is_string($q['correct_option_id'] ?? null) || !in_array($q['correct_option_id'], $optionIds, true)) {
                return 'correct_option_id must match one option id';
            }
            return null;
        }

        if ($type === 'multiple_choice_multiple_answer') {
            $optionIds = $validateOptions($q['options'] ?? null);
            if (!$optionIds) {
                return 'multiple_choice_multiple_answer requires 2-8 valid options';
            }
            $correct = $q['correct_option_ids'] ?? null;
            if (!is_array($correct) || count($correct) < 1) {
                return 'correct_option_ids must be a non-empty array';
            }
            $seen = [];
            foreach ($correct as $id) {
                if (!is_string($id) || !in_array($id, $optionIds, true) || isset($seen[$id])) {
                    return 'correct_option_ids must be unique and match option ids';
                }
                $seen[$id] = true;
            }
            return null;
        }

        if ($type === 'matching') {
            $promptIds = $validateOptions($q['prompts'] ?? null);
            $choiceIds = $validateOptions($q['choices'] ?? null);
            if (!$promptIds || !$choiceIds) {
                return 'matching requires valid prompts and choices arrays';
            }
            $matches = $q['correct_matches'] ?? null;
            if (!is_array($matches) || count($matches) !== count($promptIds)) {
                return 'matching requires exactly one match per prompt';
            }
            $seenPrompt = [];
            $seenChoice = [];
            foreach ($matches as $match) {
                if (!is_array($match)) {
                    return 'matching contains invalid match record';
                }
                $promptId = $match['prompt_id'] ?? null;
                $choiceId = $match['choice_id'] ?? null;
                if (!is_string($promptId) || !in_array($promptId, $promptIds, true) || isset($seenPrompt[$promptId])) {
                    return 'matching prompt_id must be unique and valid';
                }
                if (!is_string($choiceId) || !in_array($choiceId, $choiceIds, true) || isset($seenChoice[$choiceId])) {
                    return 'matching choice_id must be unique and valid';
                }
                $seenPrompt[$promptId] = true;
                $seenChoice[$choiceId] = true;
            }
            return null;
        }

        if ($type === 'fill_in_blank') {
            $blanks = $q['blanks'] ?? null;
            if (!is_array($blanks) || count($blanks) < 1 || count($blanks) > 8) {
                return 'fill_in_blank requires 1-8 blanks';
            }
            $promptHtml = $q['prompt_html'];
            $seenBlank = [];
            foreach ($blanks as $blank) {
                if (!is_array($blank)) {
                    return 'fill_in_blank contains invalid blank';
                }
                $id = $blank['id'] ?? null;
                $answers = $blank['acceptable_answers'] ?? null;
                if (!is_string($id) || trim($id) === '' || isset($seenBlank[$id])) {
                    return 'fill_in_blank blank IDs must be unique non-empty strings';
                }
                if (!is_array($answers) || count($answers) < 1) {
                    return 'fill_in_blank blank acceptable_answers must be non-empty arrays';
                }
                foreach ($answers as $ans) {
                    if (!is_string($ans) || trim($ans) === '') {
                        return 'fill_in_blank acceptable_answers must contain non-empty strings';
                    }
                }
                if (!str_contains($promptHtml, '[[' . $id . ']]')) {
                    return 'fill_in_blank prompt_html must include each [[blank_id]] token';
                }
                $seenBlank[$id] = true;
            }
            return null;
        }

        if ($type === 'ordering') {
            $itemIds = $validateOptions($q['items'] ?? null);
            if (!$itemIds) {
                return 'ordering requires 2-8 valid items';
            }
            $order = $q['correct_order'] ?? null;
            if (!is_array($order) || count($order) !== count($itemIds)) {
                return 'ordering correct_order must include each item id exactly once';
            }
            $seen = [];
            foreach ($order as $id) {
                if (!is_string($id) || !in_array($id, $itemIds, true) || isset($seen[$id])) {
                    return 'ordering correct_order contains invalid or duplicate item id';
                }
                $seen[$id] = true;
            }
            return null;
        }

        if ($type === 'numeric') {
            $mode = $q['answer_mode'] ?? null;
            if (!is_string($mode) || !in_array($mode, ['exact', 'tolerance'], true)) {
                return 'numeric answer_mode must be exact or tolerance';
            }
            if ($mode === 'exact') {
                if (!is_numeric($q['correct_value'] ?? null)) {
                    return 'numeric exact mode requires correct_value';
                }
                return null;
            }
            $hasTargetTolerance = is_numeric($q['target_value'] ?? null) && is_numeric($q['tolerance'] ?? null);
            $hasRange = is_numeric($q['min_value'] ?? null) && is_numeric($q['max_value'] ?? null);
            if (!$hasTargetTolerance && !$hasRange) {
                return 'numeric tolerance mode requires target+tolerance or min/max';
            }
            if ($hasTargetTolerance && ((float) $q['tolerance']) < 0) {
                return 'numeric tolerance must be >= 0';
            }
            if ($hasRange && ((float) $q['min_value']) > ((float) $q['max_value'])) {
                return 'numeric min_value must be <= max_value';
            }
            return null;
        }

        return 'Unsupported structured question type';
    }

    private function validatePackage(array $pkg): array
    {
        $violations = [];

        $html = $pkg['html'] ?? '';
        $js   = $pkg['js']   ?? '';

        // ---- HTML checks ----
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

        // ---- JS checks ----
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

    private function getLtiUserId(string $sub): int
    {
        $now = now();

        DB::table('lti_users')->insertOrIgnore([
            'sub' => $sub,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('lti_users')
            ->where('sub', $sub)
            ->update(['updated_at' => $now]);

        $userId = DB::table('lti_users')->where('sub', $sub)->value('id');

        return (int) $userId;
    }
}
