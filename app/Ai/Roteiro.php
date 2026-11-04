<?php

namespace App\Ai;

/**
 * A estrutura fixa do método (skill roteiro-reels-audiense, seção 2):
 * 11 blocos, todos obrigatórios, sempre nesta ordem.
 *
 * A IA responde num objeto com uma chave por bloco (o JSON Schema exige todas),
 * e aqui isso vira a lista de blocos rotulados que o app guarda e mostra.
 */
class Roteiro
{
    /** chave => rótulo, na ordem do método */
    public const BLOCKS = [
        'headline' => 'HEADLINE',
        'intensificador_misterio' => 'INTENSIFICADOR DE MISTÉRIO',
        'cta_salvamento' => 'CTA DE SALVAMENTO',
        'notavel_1' => 'CONTEÚDO NOTÁVEL 1',
        'notavel_2' => 'CONTEÚDO NOTÁVEL 2',
        'cta_compartilhamento' => 'CTA DE COMPARTILHAMENTO',
        'notavel_3' => 'CONTEÚDO NOTÁVEL 3',
        'crenca' => 'CRENÇA',
        'prova' => 'PROVA',
        'cta_seguir' => 'APRESENTAÇÃO MAGNÉTICA + CTA PARA SEGUIR',
        'cta_comentar' => 'CTA PARA COMENTAR',
    ];

    public const NOTAVEIS = ['notavel_1', 'notavel_2', 'notavel_3'];

    /** Rótulos antigos aceitos ao ler roteiros já salvos. */
    private const ALIASES = ['cta_seguir' => ['CTA PARA SEGUIR']];

    /** Lista legível da ordem, para os prompts. */
    public static function orderText(): string
    {
        return implode(' → ', array_map(fn ($l) => ucfirst(mb_strtolower($l)), self::BLOCKS));
    }

    /** JSON Schema do objeto "roteiro": as 11 chaves são obrigatórias. */
    public static function schema(): array
    {
        $props = [];
        foreach (self::BLOCKS as $key => $label) {
            $p = [
                'texto' => ['type' => 'string', 'description' => match ($key) {
                    'headline' => 'A headline literal',
                    'cta_seguir' => 'A apresentação magnética (quem é + missão, numa frase; a do perfil se existir) seguida do pedido para seguir com o motivo. Nunca vazio.',
                    default => 'Modo estrutura: tópicos, um por linha começando com "- ". Modo script: o texto falado. [NA TELA: ...] quando houver. Nunca vazio.',
                }],
                'segundos' => ['type' => 'integer', 'description' => 'Tempo estimado de fala deste bloco'],
            ];
            if (in_array($key, self::NOTAVEIS, true)) {
                $p = ['elementos' => ['type' => 'string', 'description' => 'Elemento(s) de conteúdo notável usados, ex.: "fatos curiosos + prova"']] + $p;
            }
            $props[$key] = [
                'type' => 'object',
                'description' => "[{$label}] — obrigatório",
                'properties' => $p,
                'required' => array_keys($p),
                'additionalProperties' => false,
            ];
        }

        return [
            'type' => 'object',
            'description' => 'Os 11 blocos do método, todos obrigatórios e na ordem: '.self::orderText(),
            'properties' => $props,
            'required' => array_keys(self::BLOCKS),
            'additionalProperties' => false,
        ];
    }

    /** Objeto da IA → lista de blocos rotulados, sempre os 11 e na ordem. */
    public static function toBlocks(array $roteiro, ?string $headline = null): array
    {
        $blocks = [];
        foreach (self::BLOCKS as $key => $label) {
            $b = is_array($roteiro[$key] ?? null) ? $roteiro[$key] : ['texto' => (string) ($roteiro[$key] ?? '')];
            $rotulo = $label;
            if (in_array($key, self::NOTAVEIS, true) && trim((string) ($b['elementos'] ?? '')) !== '') {
                $rotulo .= ' — '.trim($b['elementos']);
            }
            $texto = trim((string) ($b['texto'] ?? ''));
            if ($key === 'headline' && $headline) {
                $texto = $headline; // a headline nunca muda
            }
            $blocks[] = array_filter([
                'rotulo' => "[{$rotulo}]",
                'texto' => $texto,
                'segundos' => isset($b['segundos']) ? (int) $b['segundos'] : null,
            ], fn ($v) => $v !== null);
        }

        return $blocks;
    }

    /** Lista de blocos → objeto por chave (para mandar o roteiro atual à IA). */
    public static function fromBlocks(array $blocks): array
    {
        $out = [];
        foreach (self::BLOCKS as $key => $label) {
            $b = self::find($blocks, $key);
            $item = ['texto' => $b['texto'] ?? '', 'segundos' => (int) ($b['segundos'] ?? 0)];
            if (in_array($key, self::NOTAVEIS, true)) {
                $item = ['elementos' => self::elements($b['rotulo'] ?? '')] + $item;
            }
            $out[$key] = $item;
        }

        return $out;
    }

    /** Chaves dos blocos que faltam ou estão vazios. */
    public static function missing(array $blocks): array
    {
        $missing = [];
        foreach (array_keys(self::BLOCKS) as $key) {
            $b = self::find($blocks, $key);
            if (! $b || trim((string) ($b['texto'] ?? '')) === '') {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /**
     * Junta uma versão nova (ex.: depois da checagem de fatos) com a anterior:
     * bloco que sumiu ou veio vazio volta como estava. Resultado sempre com os 11, na ordem.
     */
    public static function mergeKeepingAll(array $newBlocks, array $previousBlocks): array
    {
        $result = [];
        foreach (array_keys(self::BLOCKS) as $key) {
            $b = self::find($newBlocks, $key);
            if (! $b || trim((string) ($b['texto'] ?? '')) === '') {
                $b = self::find($previousBlocks, $key);
            }
            if ($b) {
                $result[] = $b;
            }
        }

        return $result;
    }

    /** Localiza o bloco de uma chave pelo rótulo. */
    public static function find(array $blocks, string $key): ?array
    {
        $label = self::BLOCKS[$key];
        foreach ($blocks as $b) {
            $r = mb_strtoupper(trim((string) ($b['rotulo'] ?? ''), "[] \t"));
            $r = trim(explode('—', $r)[0]);
            if ($r === $label || in_array($r, self::ALIASES[$key] ?? [], true)) {
                return $b;
            }
        }

        return null;
    }

    private static function elements(string $rotulo): string
    {
        $parts = explode('—', trim($rotulo, '[] '), 2);

        return trim($parts[1] ?? '');
    }

    /** Cola de gravação (modo estrutura): uma linha por bloco. */
    public static function cola(array $blocks): string
    {
        return collect($blocks)->map(fn ($b) => trim($b['rotulo'].' '.str_replace("\n", ' · ', preg_replace('/^\s*-\s*/m', '', trim($b['texto'])))))->implode("\n");
    }

    /** Roteiro limpo (modo script): só a fala. */
    public static function clean(array $blocks): string
    {
        return collect($blocks)->pluck('texto')->filter()->implode("\n\n");
    }
}
