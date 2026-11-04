<?php

namespace App\Ai\Providers;

use App\Ai\LlmResult;

interface LlmProvider
{
    /**
     * @param  array|null  $schema  JSON Schema da resposta (structured output). Ignorado quando $webSearch = true.
     * @param  string|null  $effort  low|medium|high (só Anthropic)
     */
    public function generate(string $system, string $user, ?array $schema = null, bool $webSearch = false, ?string $effort = null): LlmResult;

    public function name(): string;

    public function model(): string;
}
