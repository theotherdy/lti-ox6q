<?php

namespace App\Http\Controllers;

use App\Services\Generation\FreestyleGenerationService;
use App\Services\Generation\LlmGenerationService;
use App\Services\Generation\StructuredGenerationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GenerateAppController extends Controller
{
    private const KIND_OPEN_INTERACTION = 'open_interaction';
    private const KIND_STRUCTURED_QUESTION_SET = 'structured_question_set';

    private const QUESTION_TYPE_IMAGE_HOTSPOT_SINGLE = 'image_hotspot_single';
    private readonly StructuredGenerationService $structuredGeneration;
    private readonly FreestyleGenerationService $freestyleGeneration;
    private readonly LlmGenerationService $llm;

    public function __construct(
        ?StructuredGenerationService $structuredGeneration = null,
        ?FreestyleGenerationService $freestyleGeneration = null,
        ?LlmGenerationService $llm = null
    ) {
        $this->structuredGeneration = $structuredGeneration ?? app(StructuredGenerationService::class);
        $this->freestyleGeneration = $freestyleGeneration ?? app(FreestyleGenerationService::class);
        $this->llm = $llm ?? app(LlmGenerationService::class);
    }

    public function generate(Request $request)
    {
        $request->validate([
            'prompt' => 'required|string|max:10000',
            'app_id' => 'nullable|integer|exists:apps,id',
            'preview' => 'nullable|boolean',
            'base_package' => 'nullable|array',
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

        $existingAppForLlm = ($startFresh) ? null : $existingApp;

        $basePackage = $request->input('base_package');
        if (
            $preview
            && !$startFresh
            && $generationMode === self::KIND_OPEN_INTERACTION
            && is_array($basePackage)
            && ($basePackage['kind'] ?? self::KIND_OPEN_INTERACTION) === self::KIND_OPEN_INTERACTION
        ) {
            $existingAppForLlm = (object) [
                '__baseline' => 'base_package',
                'id' => $existingApp ? (int) $existingApp->id : null,
                'title' => is_string($basePackage['title'] ?? null)
                    ? $basePackage['title']
                    : (string) ($existingApp->title ?? 'Generated app'),
                'kind' => self::KIND_OPEN_INTERACTION,
                'html' => is_string($basePackage['html'] ?? null) ? $basePackage['html'] : '',
                'css' => is_string($basePackage['css'] ?? null) ? $basePackage['css'] : '',
                'js' => is_string($basePackage['js'] ?? null) ? $basePackage['js'] : '',
                'source_jsx' => is_string($basePackage['source_jsx'] ?? null)
                    ? $basePackage['source_jsx']
                    : (is_string($basePackage['js'] ?? null) ? $basePackage['js'] : ''),
            ];
        }

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
            if (!is_string($questionType) || !in_array($questionType, StructuredGenerationService::STRUCTURED_TYPES, true)) {
                return response()->json([
                    'error' => 'question_type is required and must be one of: ' . implode(', ', StructuredGenerationService::STRUCTURED_TYPES),
                ], 422);
            }
        } else {
            if ($questionType !== null && $questionType !== '') {
                return response()->json([
                    'error' => 'question_type must be omitted when generation_mode is open_interaction',
                ], 422);
            }
        }

        Log::info('Generation mode requested', [
            'requested_mode' => $generationMode,
            'requested_question_type' => $questionType,
            'has_existing_app' => (bool) $existingApp,
            'preview' => $preview,
            'has_base_package' => is_array($basePackage),
            'base_package_kind' => is_array($basePackage) ? ($basePackage['kind'] ?? null) : null,
            'baseline_source' => (is_object($existingAppForLlm) && property_exists($existingAppForLlm, '__baseline'))
                ? $existingAppForLlm->__baseline
                : 'database',
        ]);

        if ($generationMode === self::KIND_STRUCTURED_QUESTION_SET) {
            $structured = $this->structuredGeneration->generateStructuredQuestionSet(
                (string) $request->prompt,
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
                        'source_jsx' => null,
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
                        'source_jsx' => null,
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

        $beforeOpenPackage = null;
        if ($existingAppForLlm && ($existingAppForLlm->kind ?? self::KIND_OPEN_INTERACTION) === self::KIND_OPEN_INTERACTION) {
            $beforeOpenPackage = [
                'title' => (string) ($existingAppForLlm->title ?? ''),
                'html' => (string) ($existingAppForLlm->html ?? ''),
                'css' => (string) ($existingAppForLlm->css ?? ''),
                'js' => (string) ($existingAppForLlm->source_jsx ?? $existingAppForLlm->js ?? ''),
            ];
        }

        $analysisChunk = ($visionAnalysis && trim($visionAnalysis) !== '')
            ? "\nVISION_ANALYSIS:\n{$visionAnalysis}\nUse it when relevant."
            : '';

        $openResult = $this->freestyleGeneration->generatePackage(
            (string) $request->prompt,
            $existingAppForLlm,
            $beforeOpenPackage,
            $this->buildAvailableAssetsContext($availableAssets),
            $analysisChunk,
            $finalModel,
            $visionInputs
        );

        if (($openResult['status'] ?? 500) !== 200) {
            return response()->json($openResult, $openResult['status'] ?? 500);
        }

        $package = $openResult['package'];
        $sourceJsx = (string) ($openResult['source_jsx'] ?? '');
        $appId = $existingApp ? (int) $existingApp->id : null;

        if (!$preview) {
            if ($existingApp) {
                DB::table('apps')->where('id', $existingApp->id)->update([
                    'title' => $package['title'] ?? $existingApp->title,
                    'kind' => self::KIND_OPEN_INTERACTION,
                    'html' => $package['html'] ?? "<div id='app'></div>",
                    'css' => $package['css'] ?? '',
                    'js' => $package['js'] ?? '',
                    'source_jsx' => $sourceJsx,
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
                    'source_jsx' => $sourceJsx,
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
            'source_jsx' => $sourceJsx,
            'runtime' => (string) ($openResult['runtime'] ?? 'react_jsx'),
            'transpile_status' => (string) ($openResult['transpile_status'] ?? 'ok'),
            'lifecycle_status' => (string) ($appRow->lifecycle_status ?? 'inserted'),
            'inserted_at' => $appRow->inserted_at ?? null,
            'generation_path' => $generationPath,
            'used_asset_ids' => array_values(array_map(fn ($a) => (string) ($a['asset_id'] ?? ''), $selectedVisionAssets)),
            'warnings' => $warnings,
        ];

        if (!empty($openResult['did_auto_retry'])) {
            $response['auto_retry'] = true;
        }
        if (!empty($openResult['no_op_revision_detected'])) {
            $response['no_op_revision_detected'] = true;
        }
        if (is_array($openResult['change_summary'] ?? null)) {
            $response['change_summary'] = $openResult['change_summary'];
        }
        if (is_array($openResult['human_change_summary'] ?? null)) {
            $response['human_change_summary'] = $openResult['human_change_summary'];
        }

        return response()->json($response);
    }

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

        $result = $this->llm->callLLM($messages, $visionModel);
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

    // Backward-compat test proxy: normalization logic now lives in StructuredGenerationService.
    private function normalizeStructuredQuestionSetByType(
        array $payload,
        string $questionType,
        float $confidence,
        ?array $existingStructured = null
    ): ?array {
        return $this->structuredGeneration->normalizeStructuredQuestionSetByType(
            $payload,
            $questionType,
            $confidence,
            $existingStructured
        );
    }
}
