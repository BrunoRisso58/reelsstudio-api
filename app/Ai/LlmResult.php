<?php

namespace App\Ai;

class LlmResult
{
    public function __construct(
        public string $text,
        public ?array $json = null,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        /** @var array<int, array{title:string,url:string}> */
        public array $sources = [],
        public string $provider = '',
        public string $model = '',
    ) {}
}
