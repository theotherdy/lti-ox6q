<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class GenerateAppController extends Controller
{
    
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
            'prompt' => 'required|string|max:1000',
        ]);

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
- Do NOT use <form>.
- Never set a form action or submit data via the browser.
- Treat buttons as JavaScript triggers, not HTML submit actions.
- Mount into an element with id="app".
- Use window.sdk.getState(), setState(), notify() where appropriate.
SYS;

        $user = <<<USR
{$request->prompt}

Return JSON only.
USR;

        $res = Http::withToken(config('services.openai.key'))
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4.1-mini',
                'temperature' => 0.4,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
            ]);

        if (!$res->ok()) {
            return response()->json([
                'error' => 'OpenAI request failed',
            ], 500);
        }

        $text = $res['choices'][0]['message']['content'] ?? '';

        \Log::debug('LLM raw response', [
            'content' => $text,
        ]);

        if (!$text) {
            return response()->json([
                'error' => 'OpenAI returned no content',
                'raw' => $data,
            ], 500);
        }

        $package = null;

        // 1) Try direct decode first
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            $package = $decoded;
        } else {
            // 2) Strip Markdown fences if present
            $clean = trim($text);

            // Remove ```json or ``` fences
            $clean = preg_replace('/^```(?:json)?/i', '', $clean);
            $clean = preg_replace('/```$/', '', $clean);
            $clean = trim($clean);

            // 3) Try decode again
            $decoded = json_decode($clean, true);
            if (is_array($decoded)) {
                $package = $decoded;
            } else {
                // 4) Last resort: extract first JSON object
                if (preg_match('/\{.*\}/s', $clean, $m)) {
                    $decoded = json_decode($m[0], true);
                    if (is_array($decoded)) {
                        $package = $decoded;
                    }
                }
            }
        }

        if (!$package) {
            \Log::warning('Failed to parse LLM JSON', ['raw' => $text]);
            return response()->json([
                'error' => 'LLM output could not be parsed as JSON',
                'raw' => $text,
            ], 500);
        }

        $violations = $this->validatePackage($package);

        if (!empty($violations)) {
            \Log::info('LLM output failed validation', [
                'violations' => $violations,
                'package' => $package,
            ]);

            return response()->json([
                'error' => 'Generated app violates sandbox rules',
                'violations' => $violations,
            ], 422);
        }


        // Minimal normalisation
        return response()->json([
            'id' => 'generated',
            'title' => $package['title'] ?? 'Generated app',
            'html' => $package['html'] ?? "<div id='app'></div>",
            'css' => $package['css'] ?? '',
            'js' => $package['js'] ?? '',
        ]);
    }
}
