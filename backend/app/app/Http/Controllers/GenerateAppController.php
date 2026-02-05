<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GenerateAppController extends Controller
{
    /**
     * Call the LLM and parse the JSON response
     *
     * @param array $messages The messages to send to the LLM
     * @return array{package: array|null, error: string|null, raw: string|null}
     */
    private function callLLM(array $messages): array
    {
        $startTime = microtime(true);

        try {
            /** @var \Illuminate\Http\Client\Response $res */
            $res = Http::withToken(config('services.openai.key'))
                ->timeout(90)
                ->retry(2, 1000)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => env('OPENAI_MODEL', 'gpt-4.1-mini'),
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

            $text = $responseData['choices'][0]['message']['content'] ?? '';

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

        // Parse the JSON response
        $package = $this->parseJsonResponse($text);

        if (!$package) {
            Log::warning('Failed to parse LLM JSON', ['raw' => $text]);
            return ['package' => null, 'error' => 'LLM output could not be parsed as JSON', 'raw' => $text];
        }

        return ['package' => $package, 'error' => null, 'raw' => $text];
    }

    /**
     * Parse JSON from LLM response, handling markdown fences
     */
    private function parseJsonResponse(string $text): ?array
    {
        // 1) Try direct decode first
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // 2) Strip Markdown fences if present
        $clean = trim($text);
        $clean = preg_replace('/^```(?:json)?/i', '', $clean);
        $clean = preg_replace('/```$/', '', $clean);
        $clean = trim($clean);

        // 3) Try decode again
        $decoded = json_decode($clean, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // 4) Last resort: extract first JSON object
        if (preg_match('/\{.*\}/s', $clean, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    //violations detrerministrically chceked for
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




    public function generate(Request $request)
    {
        $request->validate([
            'prompt' => 'required|string|max:10000',
            'app_id' => 'nullable|integer|exists:apps,id',
            'preview' => 'nullable|boolean',
        ]);

        // Fetch existing app if this is a revision
        $existingApp = null;
        if ($request->has('app_id')) {
            $existingApp = DB::table('apps')->where('id', $request->app_id)->first();
            if (!$existingApp) {
                return response()->json(['error' => 'App not found'], 404);
            }
        }

        // Build context-aware prompts for revision vs new generation
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
SYS;

            $user = <<<USR
User's revision request: {$request->prompt}

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
SYS;

            $user = <<<USR
{$request->prompt}

Return JSON only.
USR;
        }

        // Log the API request attempt with full details
        Log::info('OpenAI API request initiated', [
            'prompt_length' => strlen($request->prompt),
            'model' => env('OPENAI_MODEL', 'gpt-4.1-mini'),
            'temperature' => env('OPENAI_TEMPERATURE', 0.3),
            'is_revision' => (bool)$existingApp,
        ]);

        // Log exactly what's being sent to the LLM (for debugging)
        Log::debug('LLM request payload', [
            'system_message' => $system,
            'user_message' => $user,
        ]);

        // Build initial messages
        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];

        // First attempt
        $result = $this->callLLM($messages);

        if ($result['error']) {
            $statusCode = $result['raw'] ? 500 : ($result['error'] === 'Unable to connect to AI service. Please check your connection and try again.' ? 503 : 500);
            return response()->json([
                'error' => $result['error'],
                'raw' => $result['raw'],
            ], $statusCode);
        }

        $package = $result['package'];
        $violations = $this->validatePackage($package);
        $didAutoRetry = false;

        // If validation failed, try ONE automatic retry with feedback
        if (!empty($violations)) {
            Log::info('LLM output failed validation, attempting auto-retry', [
                'violations' => $violations,
                'package' => $package,
            ]);

            // Build correction message
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

            // Add the assistant's response and our correction to the conversation
            $messages[] = ['role' => 'assistant', 'content' => $result['raw']];
            $messages[] = ['role' => 'user', 'content' => $correctionMessage];

            Log::info('Auto-retry initiated for validation failure');
            Log::debug('LLM retry request payload', [
                'correction_message' => $correctionMessage,
            ]);

            // Retry
            $retryResult = $this->callLLM($messages);

            if ($retryResult['error']) {
                // Retry failed, return original validation error
                return response()->json([
                    'error' => 'Generated app violates sandbox rules (auto-retry also failed)',
                    'violations' => $violations,
                ], 422);
            }

            $retryPackage = $retryResult['package'];
            $retryViolations = $this->validatePackage($retryPackage);

            if (!empty($retryViolations)) {
                Log::info('LLM auto-retry still failed validation', [
                    'violations' => $retryViolations,
                    'package' => $retryPackage,
                ]);

                return response()->json([
                    'error' => 'Generated app violates sandbox rules',
                    'violations' => $retryViolations,
                    'auto_retry_attempted' => true,
                ], 422);
            }

            // Retry succeeded!
            Log::info('LLM auto-retry succeeded');
            $package = $retryPackage;
            $didAutoRetry = true;
        }


        // Only persist if not in preview mode
        $preview = $request->input('preview', false);

        if (!$preview) {
            // Persist or update the app
            if ($existingApp) {
                // Update existing app
                DB::table('apps')->where('id', $existingApp->id)->update([
                    'title' => $package['title'] ?? $existingApp->title,
                    'html' => $package['html'] ?? '',
                    'css' => $package['css'] ?? '',
                    'js' => $package['js'] ?? '',
                    'updated_at' => now(),
                ]);
                $appId = $existingApp->id;
            } else {
                // Insert new app
                $appId = DB::table('apps')->insertGetId([
                    'title' => $package['title'] ?? 'Generated app',
                    'html' => $package['html'] ?? "<div id='app'></div>",
                    'css' => $package['css'] ?? '',
                    'js' => $package['js'] ?? '',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } else {
            // Preview mode - don't persist, just return the package
            $appId = $existingApp ? $existingApp->id : null;
        }

        // If we have LTI context, map this app to the resource link
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
        }

        // Minimal normalisation
        $response = [
            'id' => $appId,
            'title' => $package['title'] ?? 'Generated app',
            'html' => $package['html'] ?? "<div id='app'></div>",
            'css' => $package['css'] ?? '',
            'js' => $package['js'] ?? '',
        ];

        // Let frontend know if we auto-retried
        if ($didAutoRetry) {
            $response['auto_retry'] = true;
        }

        return response()->json($response);
    }
}
