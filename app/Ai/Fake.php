<?php

namespace App\Ai;

/**
 * Respostas simuladas (AI_FAKE=true) com o mesmo formato das reais.
 * Servem para testar o app de ponta a ponta sem gastar API.
 */
class Fake
{
    public static function searchTerms(string $scope): array
    {
        return ['termos_pt' => ['como orar', 'ansiedade bíblia', 'vida devocional'], 'termos_en' => ['how to pray', 'christian anxiety']];
    }

    public static function research(string $scope): array
    {
        return [
            'recorte_adotado' => $scope,
            'resumo' => '[SIMULADO] Rodada de '.now()->format('d/m/Y').", recorte \"{$scope}\". Resposta de teste: nenhum dado foi verificado.",
            'assuntos' => [
                [
                    'assunto' => '[SIMULADO] "Não consigo mais orar"', 'categoria' => 'nicho', 'tipo' => 'dor', 'emocao' => 'Quente (culpa, alívio)',
                    'validade' => 'atemporal', 'evidencia' => 'YouTube: exemplo, não verificado', 'forca' => 4,
                    'links' => [['titulo' => 'Exemplo', 'url' => 'https://youtube.com', 'views' => 'não verificado']],
                    'por_que_conecta' => 'Milhões de cristãos passam por fases de oração seca.',
                ],
                [
                    'assunto' => '[SIMULADO] Geração ansiosa', 'categoria' => 'universal', 'tipo' => 'medo / obra', 'emocao' => 'Quente (medo)',
                    'validade' => 'atemporal', 'evidencia' => 'Livro best-seller; views não verificadas', 'forca' => 3,
                    'links' => [], 'por_que_conecta' => 'Pais e adolescentes se identificam.',
                ],
            ],
            'top5' => [['assunto' => '[SIMULADO] "Não consigo mais orar"', 'porque' => 'Dor comum com emoção quente.']],
            'headlines' => [['assunto' => '[SIMULADO] "Não consigo mais orar"', 'headline' => 'O diabo odeia quando o cristão ora sem vontade nenhuma de orar.', 'palavra_forte' => 'odeia', 'variacao_intensa' => 'O diabo odeia quando você ora sem vontade nenhuma.']],
            'momento' => [],
            'descartados' => [],
            'fontes' => [],
        ];
    }

    public static function headlines(string $topic, string $mode = 'A'): array
    {
        $list = [
            ['headline' => "O diabo odeia quando o cristão faz essas três coisas sobre {$topic}.", 'molde' => 'T7', 'molde_texto' => '[Inimigo] [adora / odeia] [quando / pessoas que / (público) que] [ação ultra específica OU "fazem essas três coisas"].', 'referencia_original' => 'O abusador adora mulheres que não sabem identificar esses três sinais.'],
            ['headline' => "Esse é o maior motivo de {$topic} parecer tão difícil.", 'molde' => 'T4', 'molde_texto' => 'Esse é o maior motivo [de / que faz] [situação].', 'referencia_original' => 'Esse é o maior motivo do brasileiro não querer sair dos Estados Unidos.'],
            ['headline' => "Sinais de que você é um cristão cansado, mas ninguém tem coragem de te dizer.", 'molde' => 'T22', 'molde_texto' => 'Sinais de que você é [adjetivo forte, positivo ou negativo], [intensificador].', 'referencia_original' => 'Sinais de que você é uma pessoa chata, mas ninguém tem coragem de te dizer.'],
            ['headline' => 'Na próxima vez que você for fazer uma oração, não peça nada para Deus.', 'molde' => 'T11', 'molde_texto' => 'Na próxima vez que você for [situação cotidiana], não [ação contraintuitiva].', 'referencia_original' => 'Na próxima vez que você for conversar com uma pessoa, não olhe para os olhos dela.'],
            ['headline' => 'E é assim que os jovens estão largando a balada e começando a ler a Bíblia.', 'molde' => 'T23', 'molde_texto' => 'E é assim que [situação] está acontecendo.', 'referencia_original' => 'E é assim que o cérebro das crianças e dos bebês está ficando secretamente dopado.'],
        ];
        $headlines = array_map(fn ($h) => $h + ['conteudo' => '[SIMULADO] O que o vídeo entrega.', 'intensificador_opcional' => '', 'assuntos_virais' => [$topic], 'grupo' => $mode === 'B' ? 'Mesmo assunto, outros moldes' : ''], $list);

        return [
            'leitura_adotada' => '',
            'headlines' => $headlines,
            'top' => array_map(fn ($h, $i) => ['posicao' => $i + 1, 'headline' => $h['headline'], 'molde' => $h['molde'], 'referencia_original' => $h['referencia_original'], 'entrega' => '[SIMULADO] Entrega do vídeo.', 'porque' => '[SIMULADO] Assunto de massa + emoção quente.'], $list, array_keys($list)),
            'top3_porque' => [['headline' => $list[0]['headline'], 'porque' => '[SIMULADO] Inimigo + lista gera curiosidade.']],
            'rebaixadas' => [],
            'molde_extraido' => $mode === 'C' ? '[Inimigo] [odeia] quando [público] faz [ação].' : '',
            'molde_correspondente' => $mode === 'C' ? 'T7' : '',
            'observacoes' => '[SIMULADO] A revelação fica no último terço do vídeo.',
        ];
    }

    public static function script(string $headline, ?string $instruction = null, string $mode = 'estrutura'): array
    {
        $e = $mode === 'estrutura';
        $t = fn (string $topicos, string $fala) => $e ? $topicos : $fala;
        $roteiro = [
            'headline' => ['texto' => $headline, 'segundos' => 4],
            'intensificador_misterio' => ['texto' => $t("- Negar o óbvio: não é jejum, nem vigília, nem campanha de oração\n- Gancho: é algo que acontece dentro do quarto", 'Eu não tô falando de jejum, nem de vigília, muito menos de campanha de oração.'), 'segundos' => 7],
            'cta_salvamento' => ['texto' => $t('- Salvar pra quando a oração travar de novo', 'Salva esse vídeo pra quando a oração travar de novo.'), 'segundos' => 4],
            'notavel_1' => ['elementos' => 'pontos de identificação', 'texto' => $t("- [SIMULADO] A cabeça vai pra lista de mercado no meio da oração\n- \"Se não sinto nada, melhor nem orar\"", '[SIMULADO] Você abre a boca pra orar e a cabeça vai pra lista de mercado.'), 'segundos' => 12],
            'notavel_2' => ['elementos' => 'fatos curiosos + prova', 'texto' => $t("- [SIMULADO] Jesus repetiu a mesma oração no Getsêmani (Mt 26:44)\n- [NA TELA: Mateus 26:44]", '[SIMULADO] No Getsêmani, Jesus repetiu as mesmas palavras. [NA TELA: Mateus 26:44]'), 'segundos' => 14],
            'cta_compartilhamento' => ['texto' => $t('- Alguém que parou de orar porque acha que não sente nada', 'Se você conhece alguém que parou de orar porque acha que não sente nada, manda esse vídeo pra ele.'), 'segundos' => 6],
            'notavel_3' => ['elementos' => 'valor prático', 'texto' => $t("- 1) Ore com palavras emprestadas (Salmo 13)\n- 2) Repita uma frase só\n- 3) Fique em silêncio diante de Deus", '[SIMULADO] Ore com o Salmo 13, repita uma frase só ou fique em silêncio diante de Deus.'), 'segundos' => 15],
            'crenca' => ['texto' => $t('- Oração não é sentimento, é confiança', 'Você precisa entender o seguinte: oração não é sentimento, é confiança.'), 'segundos' => 6],
            'prova' => ['texto' => $t("- Rm 8:26: o Espírito intercede com gemidos inexprimíveis\n- [NA TELA: Romanos 8:26]", 'Romanos 8:26 diz que o Espírito intercede por nós. [NA TELA: Romanos 8:26]'), 'segundos' => 7],
            'cta_seguir' => ['texto' => $t("- Apresentação (sugestão): Sou o Bruno, minha missão é ajudar jovens a ter uma fé com raiz\n- Seguir pra aprender a orar com a Bíblia", 'Eu sou o Bruno, e minha missão é ajudar jovens a ter uma fé com raiz. Me segue pra aprender a orar com a Bíblia.'), 'segundos' => 6],
            'cta_comentar' => ['texto' => $t('- Pergunta: qual foi a última vez que você orou sem vontade?', 'Comenta: qual foi a última vez que você orou sem vontade?'), 'segundos' => 4],
        ];

        return [
            'modo' => $mode, 'estrutura' => 'C', 'estrutura_motivo' => '[SIMULADO] A headline cria um mistério.',
            'tempo_estimado_segundos' => 82, 'duracao_motivo' => '', 'leitura_adotada' => '',
            'roteiro' => $roteiro,
            'apresentacao_e_sugestao' => true,
            'mudancas' => $instruction ? "[SIMULADO] Aplicada a instrução: {$instruction}" : '',
            'cerebro_usado' => '[SIMULADO] Nenhuma história do Segundo Cérebro (resposta simulada).',
        ];
    }

    public static function factcheck(array $blocks): array
    {
        return [
            'itens' => [['item' => 'Romanos 8:26 (ARA)', 'status' => 'ok', 'nota' => '[SIMULADO] Conferido.', 'fonte' => 'https://www.bibliaonline.com.br/ara/rm/8']],
            'ressalvas' => ['[SIMULADO] Checagem de teste — nada foi verificado de verdade.'],
            'fontes' => [],
            'houve_correcao' => false,
            'roteiro' => Roteiro::fromBlocks($blocks),
            'tempo_estimado_segundos' => (int) collect($blocks)->sum(fn ($b) => (int) ($b['segundos'] ?? 0)),
        ];
    }
}
