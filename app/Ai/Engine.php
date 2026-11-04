<?php

namespace App\Ai;

use App\Ai\Providers\AnthropicProvider;
use App\Ai\Providers\GeminiProvider;
use App\Ai\Providers\LlmProvider;
use App\Models\AiRun;
use App\Models\Tenant;
use RuntimeException;

/**
 * Ponto único de chamada à IA: escolhe o provedor da conta, monta o system
 * com as skills, garante JSON válido e contabiliza tokens na execução.
 */
class Engine
{
    private ?LlmProvider $provider = null;

    private ?string $system = null;

    /** @var array<string, array{title:string,url:string}> */
    public array $sources = [];

    public function __construct(private Tenant $tenant, private ?AiRun $run = null) {}

    public static function fake(): bool
    {
        return (bool) config('reels.fake');
    }

    public function provider(): LlmProvider
    {
        if ($this->provider) {
            return $this->provider;
        }
        $s = $this->tenant->aiSettingsWithDefaults();
        $shared = $this->tenant->canUseSharedKeys();

        if ($s['provider'] === 'gemini') {
            $key = $this->tenant->gemini_key ?: ($shared ? config('reels.gemini.key') : null);
            if (! $key) {
                throw new RuntimeException('Nenhuma API key do Gemini configurada. Adicione a sua em Configurações > IA.');
            }

            return $this->provider = new GeminiProvider($key, $s['gemini_model']);
        }

        $key = $this->tenant->anthropic_key ?: ($shared ? config('reels.anthropic.key') : null);
        if (! $key) {
            throw new RuntimeException('Nenhuma API key da Anthropic configurada. Adicione a sua em Configurações > IA.');
        }

        return $this->provider = new AnthropicProvider($key, $s['anthropic_model'], $s['effort']);
    }

    public function system(): string
    {
        return $this->system ??= Prompts::system($this->tenant);
    }

    /**
     * Executa uma etapa e devolve o JSON decodificado.
     *
     * @param  callable|null  $fake  Gera a resposta simulada quando AI_FAKE=true
     */
    public function json(string $task, array $schema, bool $webSearch = false, ?string $effort = null, ?callable $fake = null): array
    {
        if (self::fake()) {
            $this->run?->update(['provider' => 'fake', 'model' => 'fake']);
            usleep(400_000);

            return $fake ? $fake() : [];
        }

        $provider = $this->provider();
        $this->run?->update(['provider' => $provider->name(), 'model' => $provider->model()]);

        $prompt = $webSearch ? $task.Prompts::jsonInstruction($schema) : $task;
        $result = $provider->generate($this->system(), $prompt, $schema, $webSearch, $effort);
        $this->track($result);

        $data = $result->json ?? $this->extractJson($result->text);
        if (is_array($data)) {
            return $data;
        }

        // A resposta veio em texto livre: converte para o schema numa chamada curta, sem ferramentas.
        $this->run?->appendLog('Convertendo a resposta para o formato estruturado…');
        $conv = $provider->generate(
            'Você converte texto em JSON. Preserve todo o conteúdo e todos os números exatamente como estão; não invente nada.',
            "Converta o conteúdo abaixo para o JSON Schema pedido.\n\n<conteudo>\n{$result->text}\n</conteudo>",
            $schema,
            false,
            'low',
        );
        $this->track($conv);
        $data = $conv->json ?? $this->extractJson($conv->text);
        if (! is_array($data)) {
            throw new RuntimeException('A IA não devolveu um JSON válido.');
        }

        return $data;
    }

    private function track(LlmResult $r): void
    {
        foreach ($r->sources as $s) {
            $this->sources[$s['url']] = $s;
        }
        $this->run?->addUsage($r->inputTokens, $r->outputTokens);
    }

    public function extractJson(string $text): ?array
    {
        if (preg_match_all('/```(?:json)?\s*(\{.*?\})\s*```/s', $text, $m) && $m[1]) {
            $decoded = json_decode(end($m[1]), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end > $start) {
            $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    public function sourcesList(): array
    {
        return array_values($this->sources);
    }
}
