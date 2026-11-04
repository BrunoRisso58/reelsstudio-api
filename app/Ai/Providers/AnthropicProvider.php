<?php

namespace App\Ai\Providers;

use Anthropic\Client;
use App\Ai\LlmResult;
use RuntimeException;

class AnthropicProvider implements LlmProvider
{
    // Modelos que aceitam o fallback de recusa no servidor ("default")
    private const FALLBACK_MODELS = ['claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5', 'claude-fable-5-1'];

    private Client $client;

    public function __construct(string $apiKey, private string $model, private string $defaultEffort = 'medium')
    {
        $this->client = new Client(apiKey: $apiKey);
    }

    public function name(): string
    {
        return 'anthropic';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function generate(string $system, string $user, ?array $schema = null, bool $webSearch = false, ?string $effort = null): LlmResult
    {
        $outputConfig = ['effort' => $effort ?? $this->defaultEffort];
        if ($schema && ! $webSearch) {
            $outputConfig['format'] = ['type' => 'json_schema', 'schema' => $schema];
        }

        $tools = $webSearch
            ? [['type' => 'web_search_20260209', 'name' => 'web_search', 'maxUses' => (int) config('reels.anthropic.web_search_max_uses', 8)]]
            : null;

        $useFallback = in_array($this->model, self::FALLBACK_MODELS, true);

        // System grande e estável (skills + perfil) com cache: as etapas do pipeline reaproveitam.
        $systemBlocks = [['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']]];
        $messages = [['role' => 'user', 'content' => $user]];

        $in = 0;
        $out = 0;
        $text = '';
        $sources = [];

        // Ferramentas do servidor (web search) podem pausar o turno: continuamos até terminar.
        for ($i = 0; $i < 6; $i++) {
            $response = $this->client->beta->messages->create(
                maxTokens: (int) config('reels.anthropic.max_tokens', 32000),
                messages: $messages,
                model: $this->model,
                system: $systemBlocks,
                outputConfig: $outputConfig,
                tools: $tools,
                fallbacks: $useFallback ? 'default' : null,
                betas: $useFallback ? ['server-side-fallback-2026-07-01'] : null,
                requestOptions: ['timeout' => 900, 'maxRetries' => 2],
            );

            $in += (int) ($response->usage->inputTokens ?? 0) + (int) ($response->usage->cacheReadInputTokens ?? 0) + (int) ($response->usage->cacheCreationInputTokens ?? 0);
            $out += (int) ($response->usage->outputTokens ?? 0);

            if ($response->stopReason === 'refusal') {
                throw new RuntimeException('O modelo recusou a solicitação (stop_reason=refusal). Reformule o tema ou tente outro modelo.');
            }

            foreach ($response->content as $block) {
                $arr = json_decode(json_encode($block), true) ?? [];
                $type = $arr['type'] ?? ($block->type ?? '');
                if ($type === 'text') {
                    $text .= $arr['text'] ?? '';
                } elseif ($type === 'web_search_tool_result' && is_array($arr['content'] ?? null) && array_is_list($arr['content'])) {
                    foreach ($arr['content'] as $r) {
                        if (! empty($r['url'])) {
                            $sources[$r['url']] = ['title' => $r['title'] ?? $r['url'], 'url' => $r['url']];
                        }
                    }
                }
            }

            if ($response->stopReason !== 'pause_turn') {
                if ($response->stopReason === 'max_tokens') {
                    throw new RuntimeException('A resposta da IA foi cortada (limite de tokens). Tente novamente.');
                }
                break;
            }

            $messages[] = ['role' => 'assistant', 'content' => $response->content];
            $text = '';
        }

        return new LlmResult(
            text: $text,
            json: ($schema && ! $webSearch) ? json_decode($text, true) : null,
            inputTokens: $in,
            outputTokens: $out,
            sources: array_values($sources),
            provider: 'anthropic',
            model: $this->model,
        );
    }
}
