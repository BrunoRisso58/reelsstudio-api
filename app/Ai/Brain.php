<?php

namespace App\Ai;

use App\Models\Tenant;

/**
 * Segundo Cérebro: questionário sobre a história, o método e o posicionamento
 * do criador. As respostas ficam em tenants.brain e entram no system prompt,
 * para que os roteiros usem casos reais do criador em vez de exemplos genéricos.
 */
class Brain
{
    /** Partes do questionário, na ordem em que são perguntadas. */
    public const SECTIONS = [
        [
            'key' => 'criacao',
            'title' => 'História de criação',
            'intro' => 'Como tudo começou. Essas respostas viram a origem da sua autoridade nos Reels.',
            'questions' => [
                'vida_antes' => [
                    'q' => 'Como era a sua vida antes de trabalhar com o que você trabalha hoje?',
                    'hint' => 'Onde você estava, o que fazia, como se sentia. Detalhes concretos ajudam mais do que resumos.',
                ],
                'motivacao' => [
                    'q' => 'O que te motivou a iniciar nessa área?',
                    'hint' => 'Um momento, uma pessoa, uma frustração, uma descoberta. Qual foi o estalo?',
                ],
                'desafios' => [
                    'q' => 'Qual ou quais foram os maiores desafios que você enfrentou nesse caminho?',
                    'hint' => 'Obstáculos, erros, momentos em que pensou em desistir e como passou por eles.',
                ],
            ],
        ],
        [
            'key' => 'historias',
            'title' => 'Histórias de vida do especialista',
            'intro' => 'Agora precisamos das histórias que te forjaram na sua jornada.',
            'questions' => [
                'dores_superadas' => [
                    'q' => 'Conte histórias de dores que você superou.',
                    'hint' => 'De preferência dores que você venceu e que o seu público passa hoje. Pode contar mais de uma.',
                ],
                'descobertas' => [
                    'q' => 'Conte histórias de grandes descobertas ou ensinamentos que você teve ao longo da sua jornada.',
                    'hint' => 'Como você aprendeu o que sabe hoje, ou o que mudou o seu ponto de vista para o que você defende hoje.',
                ],
                'transformacoes' => [
                    'q' => 'Conte histórias de pessoas que você ajudou.',
                    'hint' => 'Casos reais em que o seu trabalho transformou a vida de alguém, mesmo que em pequenos detalhes. Não precisa dar o nome da pessoa.',
                ],
            ],
        ],
        [
            'key' => 'metodo',
            'title' => 'Método do especialista',
            'intro' => 'O caminho que você ensina. A IA usa isso nos blocos de valor prático.',
            'questions' => [
                'passo_a_passo' => [
                    'q' => 'Qual é o passo a passo que você ensina para a pessoa sair do ponto A até o resultado?',
                    'hint' => 'Liste as etapas na ordem. Ponto A = onde a pessoa está; resultado = onde ela chega.',
                ],
                'pilares' => [
                    'q' => 'Quais são os 3 a 5 pilares que toda pessoa precisa entender ou aplicar para ter resultado com o que você ensina?',
                    'hint' => 'Um por linha, com uma frase explicando cada um.',
                ],
                'essencial' => [
                    'q' => 'Se alguém seguisse só o essencial do que você faz, o que não poderia faltar?',
                    'hint' => 'O mínimo indispensável. Se tivesse que resumir tudo em uma coisa, qual seria?',
                ],
            ],
        ],
        [
            'key' => 'influencia',
            'title' => 'Núcleo de influência',
            'intro' => 'Quem você é para o seu público. Isso alimenta a apresentação magnética e o CTA para seguir.',
            'questions' => [
                'nome_oque_faz' => [
                    'q' => 'Qual é o seu nome e o que você faz?',
                    'hint' => 'Ex.: "Meu nome é Elias, sou especialista em atenção digital."',
                ],
                'apelido' => [
                    'q' => 'Você se autointitula por algum nome ou termo?',
                    'hint' => 'Ex.: "o rei da creatina", "o barbeiro dos milionários", "o dentista do SUS". Se ainda não tem, deixe em branco.',
                ],
                'quem_ajuda' => [
                    'q' => 'Quem você ajuda?',
                    'hint' => 'Descreva claramente o tipo de pessoa que você ajuda: pode ser por profissão, dor, momento de vida ou contexto.',
                ],
            ],
        ],
    ];

    /** Todas as chaves de pergunta, na ordem. */
    public static function keys(): array
    {
        return collect(self::SECTIONS)->flatMap(fn ($s) => array_keys($s['questions']))->all();
    }

    /** Respostas preenchidas (sem vazias), só chaves conhecidas. */
    public static function answers(Tenant $tenant): array
    {
        $raw = array_intersect_key($tenant->brain ?? [], array_flip(self::keys()));

        return array_filter(array_map(fn ($v) => trim((string) $v), $raw), fn ($v) => $v !== '');
    }

    public static function progress(Tenant $tenant): array
    {
        $total = count(self::keys());
        $done = count(self::answers($tenant));

        return ['answered' => $done, 'total' => $total, 'percent' => (int) round($done / $total * 100)];
    }

    /** Bloco do system prompt. Vazio quando o criador ainda não respondeu nada. */
    public static function forPrompt(Tenant $tenant): string
    {
        $answers = self::answers($tenant);
        if (! $answers) {
            return '';
        }

        $parts = [];
        foreach (self::SECTIONS as $s) {
            $qa = collect($s['questions'])
                ->filter(fn ($q, $k) => isset($answers[$k]))
                ->map(fn ($q, $k) => "**{$q['q']}**\n{$answers[$k]}")
                ->implode("\n\n");
            if ($qa !== '') {
                $parts[] = "## {$s['title']}\n{$qa}";
            }
        }

        return implode("\n\n", $parts);
    }
}
