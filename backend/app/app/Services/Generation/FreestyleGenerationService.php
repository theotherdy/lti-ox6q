<?php

namespace App\Services\Generation;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class FreestyleGenerationService
{
    public function __construct(private readonly LlmGenerationService $llm)
    {
    }

    public function generatePackage(
        string $prompt,
        ?object $existingApp,
        ?array $beforePackage,
        string $assetContext,
        string $analysisChunk,
        ?string $modelOverride,
        array $visionInputs
    ): array {
        $existingJsx = (string) ($existingApp->source_jsx ?? $existingApp->js ?? '');

        if ($existingApp) {
            $system = <<<SYS
You are revising an existing React learning application in a sandboxed iframe.

CURRENT APPLICATION:
Title: {$existingApp->title}

HTML:
{$existingApp->html}

CSS:
{$existingApp->css}

JSX SOURCE:
{$existingJsx}

INSTRUCTIONS:
- User wants specific changes to this application.
- Return COMPLETE revised application (not just changes).
- Output ONLY valid JSON with title, html, css, js.
- "js" must be JSX source code (not transpiled output).
- Mount into element with id="app".
- Use React + JSX only (no external libraries).
- Use window.sdk.getState(), setState(), notify() where appropriate.
- App must still work when window.sdk is unavailable (provide local in-memory state fallback).
- Do not use forms, fetch/XMLHttpRequest, external script imports, browser storage, or navigation APIs.
- Defensive coding is mandatory: treat all state/input values as unknown types.
- Coerce numeric inputs with Number(...) and guard with Number.isFinite(...) before numeric operations.
- Never call numeric methods (e.g. toFixed/toPrecision) on values that have not been validated as numbers.
SYS;

            $user = <<<USR
User's revision request: {$prompt}

{$assetContext}
{$analysisChunk}

Return complete revised JSON only.
USR;
        } else {
            $system = <<<SYS
You are generating a small React learning application to run inside a sandboxed iframe.

Rules:
- Output ONLY valid JSON.
- Do NOT wrap JSON in markdown.
- JSON must contain: title, html, css, js.
- "js" must be JSX source code (not transpiled output).
- Use React + JSX only (no external libraries).
- Mount into an element with id="app".
- Use window.sdk.getState(), setState(), notify() where appropriate.
- App must still work when window.sdk is unavailable (provide local in-memory state fallback).
- Do NOT use fetch/XMLHttpRequest/network access.
- Do NOT use <form> tags or native form submit.
- Do NOT use localStorage/sessionStorage/document.cookie/window.location.
- Prefer AVAILABLE_IMAGE_ASSETS URLs when rendering images.
- Defensive coding is mandatory: treat all state/input values as unknown types.
- Coerce numeric inputs with Number(...) and guard with Number.isFinite(...) before numeric operations.
- Never call numeric methods (e.g. toFixed/toPrecision) on values that have not been validated as numbers.
SYS;

            $user = <<<USR
{$prompt}

{$assetContext}
{$analysisChunk}

Return JSON only.
USR;
        }

        $userContent = $visionInputs !== []
            ? array_merge([['type' => 'text', 'text' => $user]], $visionInputs)
            : $user;

        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $userContent],
        ];

        $result = $this->llm->callLLM($messages, $modelOverride);
        if ($result['error']) {
            return ['error' => $result['error'], 'status' => 500];
        }

        $rawPackage = $result['package'];
        $build = $this->buildRuntimePackageFromRaw($rawPackage);
        if (!$build['ok']) {
            return ['error' => $build['error'], 'status' => 422, 'transpile_status' => 'failed'];
        }

        $package = $build['package'];
        $sourceJsx = $build['source_jsx'];
        $violations = $this->validatePackage($package, $sourceJsx);
        $noOpRevisionDetected = false;

        if (!empty($violations)) {
            return [
                'error' => 'Generated app violates sandbox rules. Please try again with a more specific revision request.',
                'status' => 422,
                'violations' => $violations,
            ];
        }

        if ($this->packageEquivalentToExisting($package, $sourceJsx, $existingApp)) {
            $noOpRevisionDetected = true;
        }

        $changeSummary = null;
        $humanChangeSummary = [];
        if ((bool) env('REVISION_CHANGE_SUMMARY_ENABLED', true) && is_array($beforePackage)) {
            $afterForSummary = $package;
            $afterForSummary['js'] = $sourceJsx;
            $changeSummary = $this->buildOpenInteractionChangeSummary($beforePackage, $afterForSummary, $noOpRevisionDetected, false);
            $humanChangeSummary = $this->buildDeterministicHumanChangeSummary($changeSummary);
            $modelSentence = $this->generateHumanChangeSummarySentence($prompt, $changeSummary);
            if (is_string($modelSentence) && $modelSentence !== '') {
                array_unshift($humanChangeSummary, $modelSentence);
                $humanChangeSummary = array_values(array_unique(array_slice($humanChangeSummary, 0, 3)));
            }
        }

        return [
            'status' => 200,
            'kind' => 'open_interaction',
            'package' => $package,
            'source_jsx' => $sourceJsx,
            'runtime' => 'react_jsx',
            'transpile_status' => 'ok',
            'no_op_revision_detected' => $noOpRevisionDetected,
            'change_summary' => $changeSummary,
            'human_change_summary' => $humanChangeSummary,
        ];
    }

    public function validatePackage(array $pkg, ?string $sourceJsx = null): array
    {
        $violations = [];

        $html = (string) ($pkg['html'] ?? '');
        $js = (string) ($pkg['js'] ?? '');
        $jsx = (string) ($sourceJsx ?? '');

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

        $forbiddenPatterns = [
            'fetch(',
            'XMLHttpRequest',
            'window.location',
            'location.reload(',
            'document.cookie',
            'localStorage',
            'sessionStorage',
            'import(',
            'importScripts(',
            'new Worker(',
            'new SharedWorker(',
            'WebSocket(',
            '<script src',
            'from "http',
            "from 'http",
        ];

        foreach ($forbiddenPatterns as $pattern) {
            if (stripos($js, $pattern) !== false || stripos($jsx, $pattern) !== false) {
                $violations[] = sprintf('Forbidden API/pattern detected: %s', $pattern);
            }
        }

        return array_values(array_unique($violations));
    }

    private function buildRuntimePackageFromRaw(array $rawPackage): array
    {
        $title = (string) ($rawPackage['title'] ?? 'Generated app');
        $html = (string) ($rawPackage['html'] ?? '<div id="app"></div>');
        $css = (string) ($rawPackage['css'] ?? '');
        $jsx = (string) ($rawPackage['js'] ?? '');
        if (trim($html) === '') {
            $html = '<div id="app"></div>';
        }

        if (trim($jsx) === '') {
            $jsx = <<<'JSX'
const root = ReactDOM.createRoot(document.getElementById('app'));
root.render(React.createElement(React.Fragment, null));
JSX;
        }

        $transpiled = $this->transpileJsx($jsx);
        if (!$transpiled['ok']) {
            return ['ok' => false, 'error' => 'Generated JSX could not be transpiled safely'];
        }

        $transpiledJs = (string) $transpiled['js'];

        $runtimeWrapper = <<<JS
(() => {
  const sdk = window.sdk;
  const hasSDK = !!(sdk && typeof sdk.getState === 'function' && typeof sdk.setState === 'function');
  let memoryState = null;

  function clone(v) {
    if (v === undefined) return null;
    try {
      return typeof structuredClone === 'function' ? structuredClone(v) : JSON.parse(JSON.stringify(v));
    } catch (_) {
      return v;
    }
  }

  const sdkFacade = {
    getState: () => {
      if (hasSDK) {
        const value = sdk.getState();
        return value === undefined ? null : value;
      }
      if (memoryState === null || memoryState === undefined) return null;
      return clone(memoryState);
    },
    setState: (next) => {
      if (hasSDK) return sdk.setState(next);
      memoryState = clone(next);
      return next;
    },
    notify: (input) => {
      const payload = typeof input === 'string'
        ? { message: input, variant: 'info' }
        : { message: String(input?.message ?? ''), variant: String(input?.variant ?? 'info') };
      if (hasSDK && typeof sdk.notify === 'function') {
        return sdk.notify(payload);
      }
      return null;
    }
  };

  window.sdk = sdkFacade;
  window.React = window.React || React;
  window.ReactDOM = window.ReactDOM || ReactDOM;

  {$transpiledJs}
})();
JS;

        return [
            'ok' => true,
            'source_jsx' => $jsx,
            'package' => [
                'title' => $title,
                'html' => $html,
                'css' => $css,
                'js' => $runtimeWrapper,
            ],
        ];
    }

    private function transpileJsx(string $source): array
    {
        $nodeBinary = $this->resolveNodeBinary();
        if ($nodeBinary === null) {
            Log::warning('JSX transpilation skipped: Node.js binary not found in runtime PATH');
            if ($this->looksLikePlainJavaScript($source)) {
                return ['ok' => true, 'js' => $source];
            }
            return ['ok' => false, 'error' => 'node_missing'];
        }

        $nodeScript = <<<'NODE'
const fs = require('fs');
let esbuild;
try {
  esbuild = require('esbuild');
} catch (e) {
  process.stderr.write('missing_esbuild');
  process.exit(2);
}

const input = fs.readFileSync(0, 'utf8');
esbuild.transform(input, {
  loader: 'jsx',
  target: 'es2020',
  format: 'iife',
  jsx: 'transform',
  jsxFactory: 'React.createElement',
  jsxFragment: 'React.Fragment',
}).then((result) => {
  process.stdout.write(result.code || '');
}).catch(() => {
  process.stderr.write('transform_failed');
  process.exit(1);
});
NODE;

        $process = new Process([$nodeBinary, '-e', $nodeScript]);
        $process->setInput($source);
        $process->setTimeout(15);
        $process->run();

        $stderr = trim($process->getErrorOutput());
        if (!$process->isSuccessful() && str_contains($stderr, 'missing_esbuild')) {
            return $this->transpileJsxViaEsbuildCli($source);
        }

        if (!$process->isSuccessful()) {
            Log::warning('JSX transpilation failed', [
                'exit_code' => $process->getExitCode(),
                'stderr' => $stderr,
            ]);
            if ($this->looksLikePlainJavaScript($source)) {
                return ['ok' => true, 'js' => $source];
            }
            return ['ok' => false, 'error' => 'transpile_failed'];
        }

        $output = trim($process->getOutput());
        if ($output === '') {
            return ['ok' => false, 'error' => 'empty_transpile_output'];
        }

        return ['ok' => true, 'js' => $output];
    }

    private function transpileJsxViaEsbuildCli(string $source): array
    {
        $process = new Process([
            'esbuild',
            '--loader=jsx',
            '--target=es2020',
            '--format=iife',
            '--log-level=error',
        ]);
        $process->setInput($source);
        $process->setTimeout(15);
        $process->run();

        if (!$process->isSuccessful()) {
            Log::warning('JSX transpilation failed via esbuild CLI', [
                'exit_code' => $process->getExitCode(),
                'stderr' => trim($process->getErrorOutput()),
            ]);
            if ($this->looksLikePlainJavaScript($source)) {
                return ['ok' => true, 'js' => $source];
            }
            return ['ok' => false, 'error' => 'transpile_failed'];
        }

        $output = trim($process->getOutput());
        if ($output === '') {
            return ['ok' => false, 'error' => 'empty_transpile_output'];
        }

        return ['ok' => true, 'js' => $output];
    }

    private function resolveNodeBinary(): ?string
    {
        $candidates = ['node', 'nodejs'];
        foreach ($candidates as $binary) {
            $probe = new Process([$binary, '--version']);
            $probe->setTimeout(3);
            $probe->run();
            if ($probe->isSuccessful()) {
                return $binary;
            }
        }
        return null;
    }

    private function looksLikePlainJavaScript(string $source): bool
    {
        if (preg_match('/(^|\\s)<[A-Za-z][^\\n>]*>/m', $source) === 1) {
            return false;
        }
        if (str_contains($source, '</') || str_contains($source, '/>')) {
            return false;
        }
        return true;
    }

    private function packageEquivalentToExisting(array $package, string $sourceJsx, ?object $existingApp): bool
    {
        if (!$existingApp) {
            return false;
        }

        $existingSourceJsx = (string) ($existingApp->source_jsx ?? $existingApp->js ?? '');

        return $this->normalizeComparisonValue((string) ($package['title'] ?? '')) === $this->normalizeComparisonValue((string) ($existingApp->title ?? ''))
            && $this->normalizeComparisonValue((string) ($package['html'] ?? '')) === $this->normalizeComparisonValue((string) ($existingApp->html ?? ''))
            && $this->normalizeComparisonValue((string) ($package['css'] ?? '')) === $this->normalizeComparisonValue((string) ($existingApp->css ?? ''))
            && $this->normalizeComparisonValue($sourceJsx) === $this->normalizeComparisonValue($existingSourceJsx);
    }

    private function normalizeComparisonValue(string $value): string
    {
        $trimmed = trim($value);
        return preg_replace('/\s+/', ' ', $trimmed) ?? $trimmed;
    }

    private function buildOpenInteractionChangeSummary(array $before, array $after, bool $noOpDetected, bool $didAutoRetry): array
    {
        $sections = ['title', 'html', 'css', 'js'];
        $changedSections = [];
        $metrics = [];

        foreach ($sections as $section) {
            $beforeValue = (string) ($before[$section] ?? '');
            $afterValue = (string) ($after[$section] ?? '');
            $beforeNorm = $this->normalizeComparisonValue($beforeValue);
            $afterNorm = $this->normalizeComparisonValue($afterValue);
            $changed = $beforeNorm !== $afterNorm;

            $metrics[$section . '_changed'] = $changed;
            $metrics[$section . '_char_delta'] = strlen($afterNorm) - strlen($beforeNorm);

            if ($changed) {
                $changedSections[] = $section;
            }
        }

        return [
            'changed' => count($changedSections) > 0,
            'changed_sections' => $changedSections,
            'metrics' => $metrics,
            'no_op_revision_detected' => $noOpDetected,
            'auto_retry' => $didAutoRetry,
        ];
    }

    private function buildDeterministicHumanChangeSummary(array $summary): array
    {
        $bullets = [];
        $sections = $summary['changed_sections'] ?? [];
        $sectionSet = array_fill_keys(is_array($sections) ? $sections : [], true);

        if (!empty($summary['changed'])) {
            if (isset($sectionSet['js'])) {
                $bullets[] = 'Interaction logic was updated.';
            }
            if (isset($sectionSet['html'])) {
                $bullets[] = 'Visible structure/content was updated.';
            } else {
                $bullets[] = 'Visual structure stayed the same.';
            }
            if (isset($sectionSet['css'])) {
                $bullets[] = 'Styling was adjusted.';
            }
            if (isset($sectionSet['title'])) {
                $bullets[] = 'The activity title was changed.';
            }
        } else {
            $bullets[] = 'The revision mostly reproduced the previous version.';
            $bullets[] = 'Try a more specific request to force clearer changes.';
        }

        if (!empty($summary['auto_retry'])) {
            $bullets[] = 'Automatic correction was applied.';
        }

        return array_values(array_slice(array_unique($bullets), 0, 3));
    }

    private function generateHumanChangeSummarySentence(string $userPrompt, array $summary): ?string
    {
        if (!(bool) env('REVISION_CHANGE_SUMMARY_MODEL_ENABLED', true)) {
            return null;
        }

        try {
            $changedSections = implode(', ', $summary['changed_sections'] ?? []);
            $facts = [
                'changed=' . (!empty($summary['changed']) ? 'true' : 'false'),
                'changed_sections=' . ($changedSections !== '' ? $changedSections : 'none'),
                'no_op_revision_detected=' . (!empty($summary['no_op_revision_detected']) ? 'true' : 'false'),
                'auto_retry=' . (!empty($summary['auto_retry']) ? 'true' : 'false'),
            ];

            $messages = [
                [
                    'role' => 'system',
                    'content' => 'Write exactly one plain sentence summarizing revision changes for a non-technical user. Use only provided facts. No markdown, no lists, no extra claims.',
                ],
                [
                    'role' => 'user',
                    'content' => "User request: {$userPrompt}\nFacts: " . implode('; ', $facts),
                ],
            ];

            $result = $this->llm->callLLMForText($messages, (string) env('OPENAI_MODEL_TEXT_FAST', env('OPENAI_MODEL', 'gpt-4.1-mini')));
            if ($result['error']) {
                return null;
            }

            $candidate = trim((string) ($result['text'] ?? ''));
            if ($candidate === '') {
                return null;
            }

            if (str_starts_with($candidate, '{') || str_starts_with($candidate, '[')) {
                return null;
            }

            $candidate = preg_replace('/\s+/', ' ', $candidate) ?? $candidate;
            $candidate = trim($candidate);
            if ($candidate === '') {
                return null;
            }

            if (preg_match('/[.!?]/', $candidate, $matches, PREG_OFFSET_CAPTURE)) {
                $endPos = $matches[0][1] + strlen($matches[0][0]);
                $candidate = substr($candidate, 0, $endPos);
            }

            return mb_substr($candidate, 0, 220);
        } catch (\Throwable $e) {
            Log::warning('Failed to generate model change summary sentence', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
