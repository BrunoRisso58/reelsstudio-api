<?php

namespace App\Ai\Providers;

use App\Ai\LlmResult;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Alternativa ao Claude. Usa a REST API do Gemini, com Google Search (grounding)
 * para as etapas de pesquisa e resposta em JSON nas demais.
 */
class GeminiProvider implements LlmProvider
{
    public function __construct(private string $apiKey, private string $model) {}

    public function name(): string
    {
        return 'gemini';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function generate(string $system, string $user, ?array $schema = null, bool $webSearch = false, ?string $effort = null): LlmResult
    {
        $body = [
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $user]]]],
            'generationConfig' => ['maxOutputTokens' => 32000],
        ];

        if ($webSearch) {
            $body['tools'] = [['google_search' => (object) []]];
        } elseif ($schema) {
            $body['generationConfig']['responseMimeType'] = 'application/json';
            $body['generationConfig']['responseJsonSchema'] = $schema;
        }

        $res = Http::timeout(600)
            ->withHeaders(['x-goog-api-key' => $this->apiKey])
            ->post("https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent", $body);

        if ($res->failed()) {
            throw new RuntimeException('Gemini: '.($res->json('error.message') ?? $res->body()));
        }

        $data = $res->json();
        $text = '';
        foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
            if (empty($part['thought'])) {
                $text .= $part['text'] ?? '';
            }
        }
        if ($text === '') {
            $reason = $data['candidates'][0]['finishReason'] ?? ($data['promptFeedback']['blockReason'] ?? 'desconhecido');
            throw new RuntimeException("Gemini não devolveu texto (motivo: {$reason}).");
        }

        $sources = [];
        foreach ($data['candidates'][0]['groundingMetadata']['groundingChunks'] ?? [] as $chunk) {
            if (! empty($chunk['web']['uri'])) {
                $sources[] = ['title' => $chunk['web']['title'] ?? $chunk['web']['uri'], 'url' => $chunk['web']['uri']];
            }
        }

        return new LlmResult(
            text: $text,
            json: ($schema && ! $webSearch) ? json_decode($text, true) : null,
            inputTokens: (int) ($data['usageMetadata']['promptTokenCount'] ?? 0),
            outputTokens: (int) (($data['usageMetadata']['candidatesTokenCount'] ?? 0) + ($data['usageMetadata']['thoughtsTokenCount'] ?? 0)),
            sources: $sources,
            provider: 'gemini',
            model: $this->model,
        );
    }
}
