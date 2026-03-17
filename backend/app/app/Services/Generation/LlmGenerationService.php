<?php

namespace App\Services\Generation;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LlmGenerationService
{
    public function callLLM(array $messages, ?string $model = null): array
    {
        return $this->callLLMForPackageJson($messages, $model);
    }

    public function callLLMForPackageJson(array $messages, ?string $model = null): array
    {
        $raw = $this->callLLMForText($messages, $model);
        if ($raw['error']) {
            return ['package' => null, 'error' => $raw['error'], 'raw' => null];
        }

        $text = (string) ($raw['text'] ?? '');

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

    public function callLLMForText(array $messages, ?string $model = null): array
    {
        $startTime = microtime(true);
        $resolvedModel = (string) ($model ?: env('OPENAI_MODEL', 'gpt-4.1-mini'));
        $useResponsesApi = $this->shouldUseResponsesApi($resolvedModel);
        $apiPath = $useResponsesApi ? '/v1/responses' : '/v1/chat/completions';
        $temperature = (float) env('OPENAI_TEMPERATURE', 0.3);
        $payload = $useResponsesApi
            ? [
                'model' => $resolvedModel,
                'temperature' => $temperature,
                'input' => $this->toResponsesInput($messages),
            ]
            : [
                'model' => $resolvedModel,
                'temperature' => $temperature,
                'messages' => $messages,
            ];

        try {
            $request = Http::withToken(config('services.openai.key'))
                ->timeout((int) env('OPENAI_TIMEOUT', 180))
                ->retry(2, 1000);

            $res = $useResponsesApi
                ? $request->post('https://api.openai.com/v1/responses', $payload)
                : $request->post('https://api.openai.com/v1/chat/completions', $payload);

            $duration = microtime(true) - $startTime;

            if ($res->status() >= 400) {
                Log::error('OpenAI API request failed', [
                    'status' => $res->status(),
                    'body' => $res->body(),
                    'duration' => round($duration, 2) . 's',
                ]);
                return ['text' => null, 'error' => 'Failed to generate app. Please try again.'];
            }

            $responseData = $res->json();

            Log::info('OpenAI API request succeeded', [
                'duration' => round($duration, 2) . 's',
                'tokens_used' => $responseData['usage']['total_tokens'] ?? null,
                'api_path' => $apiPath,
            ]);

            $text = $useResponsesApi
                ? $this->extractResponsesText($responseData)
                : $this->extractMessageText($responseData['choices'][0]['message']['content'] ?? '');
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $duration = microtime(true) - $startTime;
            Log::error('OpenAI API connection error', [
                'error' => $e->getMessage(),
                'duration' => round($duration, 2) . 's',
            ]);
            return ['text' => null, 'error' => 'Unable to connect to AI service. Please check your connection and try again.'];
        } catch (\Exception $e) {
            $duration = microtime(true) - $startTime;
            Log::error('Unexpected error during OpenAI API request', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'duration' => round($duration, 2) . 's',
            ]);
            return ['text' => null, 'error' => 'An unexpected error occurred. Please try again.'];
        }

        if (!is_string($text) || trim($text) === '') {
            return ['text' => null, 'error' => 'OpenAI returned no content'];
        }

        return ['text' => trim($text), 'error' => null];
    }

    private function shouldUseResponsesApi(string $model): bool
    {
        $normalized = strtolower(trim($model));
        return str_contains($normalized, 'codex');
    }

    private function toResponsesInput(array $messages): array
    {
        $input = [];

        foreach ($messages as $message) {
            $role = (string) ($message['role'] ?? 'user');
            $content = $message['content'] ?? '';

            $input[] = [
                'role' => $role,
                'content' => $this->toResponsesContentItems($content, $role),
            ];
        }

        return $input;
    }

    private function toResponsesContentItems($content, string $role = 'user'): array
    {
        $textType = $role === 'assistant' ? 'output_text' : 'input_text';

        if (is_string($content)) {
            return [['type' => $textType, 'text' => $content]];
        }

        if (!is_array($content)) {
            return [];
        }

        $parts = [];
        foreach ($content as $item) {
            if (!is_array($item)) {
                continue;
            }

            $type = (string) ($item['type'] ?? '');
            if ($type === 'text' && is_string($item['text'] ?? null)) {
                $parts[] = ['type' => $textType, 'text' => $item['text']];
                continue;
            }

            if ($type === 'image_url') {
                $url = $item['image_url']['url'] ?? null;
                if ($role !== 'assistant' && is_string($url) && $url !== '') {
                    $parts[] = ['type' => 'input_image', 'image_url' => $url];
                }
            }
        }

        return $parts;
    }

    private function extractResponsesText(array $responseData): string
    {
        $outputText = $responseData['output_text'] ?? null;
        if (is_string($outputText) && trim($outputText) !== '') {
            return trim($outputText);
        }

        $output = $responseData['output'] ?? null;
        if (!is_array($output)) {
            return '';
        }

        $parts = [];
        foreach ($output as $item) {
            if (!is_array($item)) {
                continue;
            }
            $content = $item['content'] ?? null;
            if (!is_array($content)) {
                continue;
            }
            foreach ($content as $contentItem) {
                if (!is_array($contentItem)) {
                    continue;
                }
                $type = (string) ($contentItem['type'] ?? '');
                if ($type === 'output_text' && is_string($contentItem['text'] ?? null)) {
                    $parts[] = $contentItem['text'];
                }
            }
        }

        return trim(implode("\n", $parts));
    }

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
}
