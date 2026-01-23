<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class GenerateAppController extends Controller
{
    public function generate(Request $request)
    {
        $request->validate([
            'prompt' => 'required|string|max:1000',
        ]);

        $system = <<<SYS
You are generating a small learning application to run inside a sandboxed iframe.

Rules:
- Output ONLY valid JSON.
- JSON must contain: title, html, css, js.
- Use plain HTML, CSS, and vanilla JavaScript.
- Do NOT use external libraries.
- Do NOT use fetch or network access.
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

        // Parse JSON defensively
        $package = json_decode($text, true);
        if (!is_array($package)) {
            return response()->json([
                'error' => 'LLM did not return valid JSON',
                'raw' => $text,
            ], 500);
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
