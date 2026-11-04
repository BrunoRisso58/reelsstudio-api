<?php

namespace App\Ai;

use App\Models\AiRun;
use App\Models\Idea;
use App\Models\Reel;
use App\Models\ResearchRound;
use App\Models\Tenant;
use App\Models\ViralRef;

/**
 * As etapas do método (reel-completo e skills individuais) como chamadas de IA.
 * Cada método monta o pedido da etapa; o system prompt (skills + perfil) é o mesmo.
 */
class Studio
{
    private Engine $engine;

    public function __construct(private Tenant $tenant, private ?AiRun $run = null)
    {
        $this->engine = new Engine($tenant, $run);
    }

    private function log(string $line): void
    {
        $this->run?->appendLog($line);
    }

    private function today(): string
    {
        return now()->format('d/m/Y');
    }

    private static function j(mixed $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    // ------------------------------------------------------------------
    // Pesquisa viral (skill assuntos-virais-cristao)
    // ------------------------------------------------------------------

    /**
     * @param  string|null  $theme  Tema do Reel (fluxo reel-completo) — recorte vira o tema e busca assuntos adjacentes.
     */
    public function research(string $scope, ?string $theme = null, ?string $notes = null, ?Reel $reel = null): ResearchRound
    {
        $settings = $this->tenant->aiSettingsWithDefaults();
        $youtube = null;

        if ($settings['youtube_research']) {
            $this->log('Gerando termos de busca para o YouTube…');
            $terms = $this->engine->json(
                "# Tarefa: termos de busca\nGere termos GENÉRICOS de busca para a etapa 1 e 2 da skill assuntos-virais-cristao (YouTube PT e EN), do jeito que as pessoas digitam (2 a 4 palavras), para o recorte abaixo. Até 6 em português e até 4 em inglês. Prefira termos de massa adjacentes ao recorte, não o título literal.\n\nRecorte: {$scope}"
                    .($notes ? "\nAnotações: {$notes}" : ''),
                Schemas::searchTerms(),
                effort: 'low',
                fake: fn () => Fake::searchTerms($scope),
            );
            $this->log('Buscando no YouTube (este ano + mais vistos): '.implode(', ', array_merge($terms['termos_pt'] ?? [], $terms['termos_en'] ?? [])));
            $youtube = (new YouTube)->collect($terms['termos_pt'] ?? [], $terms['termos_en'] ?? []);
            $count = collect($youtube['buscas'])->sum(fn ($b) => count($b['videos']));
            $this->log("YouTube: {$count} vídeos coletados com views reais.");
        }

        $ytText = $youtube ? $this->compactYoutube($youtube) : '(coleta do YouTube desativada nesta conta — use a busca na web e marque views como "não verificado" quando não as vir)';

        if ($theme) {
            $task = <<<TXT
# Etapa: pesquisa viral (reel-completo, etapa 2 — skill assuntos-virais-cristao)
Data de hoje: {$this->today()}.
O recorte da rodada é o tema abaixo: pule a pergunta da etapa 0.
Busque assuntos virais ADJACENTES ao tema nas 3 categorias: universais (crenças populares, obras, papéis), do nicho (dores, crenças, versículos, personagens bíblicos) e do momento (só se pertencer ao tema).

## Tema
{$theme}

## Anotações do criador (a espinha do conteúdo)
{$this->orNone($notes)}
TXT;
        } else {
            $task = <<<TXT
# Etapa: rodada de pesquisa de assuntos virais (skill assuntos-virais-cristao, etapas 1 a 8)
Data de hoje: {$this->today()}.
Recorte da rodada (etapa 0 já respondida): {$scope}
TXT;
        }

        $task .= <<<TXT


## Dados do YouTube coletados agora pelo app
Formato: título | canal | views | publicado | link. As views são reais (lidas na página de busca hoje). Em cada busca também vêm as sugestões do autocompletar.
{$ytText}

## Como trabalhar
- Use os dados do YouTube acima como evidência principal de views. Procure o termo que se repete nos mais vistos.
- Use a busca na web para: assuntos do momento (notícias dos últimos 7–14 dias), validar evidências e encontrar sinais em TikTok/Instagram. Se não viu a contagem numa página, escreva "não verificado".
- Valide pelos critérios da etapa 6, classifique (etapa 7) e gere headlines (etapa 8) para os mais fortes.
- No resumo, diga a data, o recorte e o que não foi verificado nesta rodada.
TXT;

        $this->log('Pesquisando e validando assuntos virais…');
        $data = $this->engine->json($task, Schemas::research(), webSearch: true, fake: fn () => Fake::research($scope));
        $data['fontes'] = array_values(array_unique(array_merge($data['fontes'] ?? [], array_column($this->engine->sourcesList(), 'url'))));

        $round = ResearchRound::create([
            'tenant_id' => $this->tenant->id,
            'scope' => mb_substr($scope, 0, 500),
            'summary' => $data['resumo'] ?? null,
            'result' => $data,
            'youtube' => $youtube,
            'reel_id' => $reel?->id,
        ]);

        // Sugestões da IA no banco (sem duplicar assuntos já existentes)
        $existing = Idea::pluck('title')->map(fn ($t) => mb_strtolower(trim($t)))->all();
        $added = 0;
        foreach ($data['assuntos'] ?? [] as $a) {
            $title = trim($a['assunto'] ?? '');
            if ($title === '' || in_array(mb_strtolower($title), $existing, true)) {
                continue;
            }
            Idea::create([
                'tenant_id' => $this->tenant->id,
                'origin' => 'ai',
                'title' => mb_substr($title, 0, 500),
                'category' => $a['categoria'] ?? null,
                'type' => $a['tipo'] ?? null,
                'emotion' => $a['emocao'] ?? null,
                'evidence' => trim(($a['evidencia'] ?? '').(! empty($a['por_que_conecta']) ? "\nPor que conecta: ".$a['por_que_conecta'] : '')),
                'links' => collect($a['links'] ?? [])->map(fn ($l) => ['title' => $l['titulo'] ?? '', 'url' => $l['url'] ?? '', 'views' => $l['views'] ?? ''])->all(),
                'validity' => $a['validade'] ?? null,
                'status' => 'ideia',
                'research_round_id' => $round->id,
            ]);
            $existing[] = mb_strtolower($title);
            $added++;
        }
        $this->log("Pesquisa concluída: ".count($data['assuntos'] ?? [])." assuntos validados, {$added} novos em Sugestões da IA.");

        return $round;
    }

    private function compactYoutube(array $yt): string
    {
        $lines = ["Coletado em {$yt['coletado_em']} — filtro: {$yt['filtro']}"];
        foreach ($yt['buscas'] as $b) {
            $lines[] = "\n### Busca \"{$b['termo']}\" ({$b['idioma']})";
            if ($b['autocompletar']) {
                $lines[] = 'Autocompletar: '.implode('; ', $b['autocompletar']);
            }
            foreach ($b['videos'] as $v) {
                $lines[] = "- {$v['title']} | {$v['channel']} | {$v['views']} | {$v['published']} | {$v['url']}";
            }
            if (! $b['videos']) {
                $lines[] = '- (sem resultados)';
            }
        }

        return implode("\n", $lines);
    }

    private function orNone(?string $v): string
    {
        return trim((string) $v) !== '' ? trim($v) : '(nenhuma)';
    }

    // ------------------------------------------------------------------
    // Headlines (skill headlines-reels-carrosseis)
    // ------------------------------------------------------------------

    /** Top 5 do fluxo reel-completo (etapa 3). */
    public function reelHeadlines(Reel $reel): array
    {
        $research = $reel->research ? self::j($this->researchForPrompt($reel->research)) : '(sem pesquisa nesta rodada — use o tema e as anotações)';

        $task = <<<TXT
# Etapa: top 5 headlines (reel-completo, etapa 3 — skill headlines-reels-carrosseis, modo A)
Formato: {$reel->format}.
Gere ~10 headlines para Reels combinando o tema com 1 a 3 assuntos virais da pesquisa (o mais forte primeiro). Aplique a Regra de Fidelidade aos moldes (seção 0) e o checklist (seção 7) em cada uma.
Depois escolha o TOP 5, ordenado por potencial: alcance do assunto viral (evidência da pesquisa), emoção quente, fidelidade bíblica e o quanto o conteúdo do criador sustenta a promessa. A nº 1 vai para o roteiro.
Guardrail: se a headline mais viral prometer o que o texto bíblico não sustenta, rebaixe-a no ranking e explique em "rebaixadas". O conteúdo do criador manda: a pesquisa escolhe a embalagem, não muda a tese.
Em "top3_porque", as 3 de maior potencial com o porquê (1 linha cada).

## Tema
{$reel->theme}

## Anotações do criador
{$this->orNone($reel->notes)}

## Pesquisa viral desta rodada
{$research}
TXT;

        $this->log('Gerando ~10 headlines e escolhendo o top 5…');

        return $this->engine->json($task, Schemas::headlines(), fake: fn () => Fake::headlines($reel->title));
    }

    /**
     * Gerador avulso: modos A (gerar), B (escalar) e C (engenharia reversa).
     *
     * @param  array{mode:string,topic?:string,audience?:string,format?:string,quantity?:int,template?:string,reference?:string,comments?:string}  $p
     */
    public function headlines(array $p): array
    {
        $mode = $p['mode'] ?? 'A';
        $format = $p['format'] ?? 'reels';
        $qty = (int) ($p['quantity'] ?? 10);

        $modeText = match ($mode) {
            'B' => "Modo B — escalar um conteúdo que viralizou. Entregue as duas listas separadas (campo \"grupo\"): \"Mesmo assunto, outros moldes\" e \"Mesmo molde, outros assuntos\". Cerca de {$qty} no total.",
            'C' => 'Modo C — engenharia reversa da headline de referência. Diga se bate com um molde da seção 4 (molde_correspondente); se não bater, extraia o molde novo mantendo as palavras fixas e marcando as variáveis com [colchetes] (molde_extraido). Depois gere de 3 a 5 aplicações no nicho seguindo a Regra de Fidelidade.',
            default => "Modo A — gerar {$qty} headlines, variando os moldes.",
        };

        $task = "# Tarefa: headlines (skill headlines-reels-carrosseis)\n{$modeText}\nFormato: {$format}."
            .(! empty($p['topic']) ? "\n\n## Assunto / tema\n{$p['topic']}" : '')
            .(! empty($p['audience']) ? "\n\n## Público específico\n{$p['audience']}" : '')
            .(! empty($p['template']) ? "\n\n## Template pedido\n{$p['template']}" : '')
            .(! empty($p['reference']) ? "\n\n## Referência (vídeo que viralizou / headline viral)\n{$p['reference']}" : '')
            .(! empty($p['comments']) ? "\n\n## Comentários / dúvidas do público\n{$p['comments']}" : '')
            ."\n\nPreencha \"top\" com as 5 melhores em ordem de potencial e \"top3_porque\" com as 3 de maior potencial e o porquê. Em \"observacoes\", as notas do formato (".($format === 'carrossel' ? 'estrutura de slides: slide 1, slide 2, miolo, último slide' : 'onde fica a revelação para segurar retenção').').';

        $this->log("Gerando headlines (modo {$mode})…");

        return $this->engine->json($task, Schemas::headlines(), fake: fn () => Fake::headlines($p['topic'] ?? ($p['reference'] ?? 'tema'), $mode));
    }

    public function reverse(ViralRef $ref): array
    {
        return $this->headlines([
            'mode' => 'C',
            'format' => 'reels',
            'reference' => $ref->headline.($ref->notes ? "\n(Notas: {$ref->notes})" : '').($ref->views ? "\n(Views: {$ref->views})" : ''),
        ]);
    }

    // ------------------------------------------------------------------
    // Roteiro (skill roteiro-reels-audiense)
    // ------------------------------------------------------------------

    public function script(Reel $reel): array
    {
        $facts = $reel->research ? self::j($this->researchForPrompt($reel->research)) : '(sem pesquisa)';
        $chosen = collect($reel->headlines['headlines'] ?? [])->firstWhere('headline', $reel->headline);
        $chosenInfo = $chosen ? "\nMolde: {$chosen['molde']} — {$chosen['molde_texto']}\nO que o vídeo precisa entregar: {$chosen['conteudo']}" : '';

        $mode = $reel->script_mode === 'script' ? 'script' : 'estrutura';
        $modeText = $mode === 'script'
            ? 'Modo: SCRIPT COMPLETO (formato 7b). O criador pediu o texto falado inteiro.'
            : 'Modo: ESTRUTURA COM IDEIAS (formato 7a, o padrão). O criador grava com as próprias palavras: tópicos curtos por bloco, não frases prontas.';

        $task = <<<TXT
# Etapa: roteiro (reel-completo, etapa 4 — skill roteiro-reels-audiense)
{$modeText}
Siga a skill à risca: princípios obrigatórios, ESTRUTURA FIXA de 11 blocos, elementos de conteúdo notável, escolha da estrutura (A, B, C ou base) pela headline para preencher os notáveis, regras de conteúdo e revisão.
{$this->structureRule()}
{$this->limitText()}

## Headline (NÃO reescrever — usar exatamente como está)
{$reel->headline}{$chosenInfo}

## Tema
{$reel->theme}

## Anotações do criador (posição dele e pontos obrigatórios; a revelação final vem daqui)
{$this->orNone($reel->notes)}

## Fatos e provas levantados na pesquisa
{$facts}

## Personalização (Segundo Cérebro)
{$this->brainRule()}

## Entrega
- "modo": "{$mode}".
- "roteiro": os 11 blocos, cada um com "texto" e "segundos" estimados; nos notáveis, também "elementos".
- "cerebro_usado": o que do Segundo Cérebro entrou e em qual bloco (vazio se nada coube).
- Tempo estimado a ~150 palavras por minuto.
TXT;

        $this->log($mode === 'script' ? 'Escrevendo o script completo no método Audiense…' : 'Montando a estrutura do roteiro no método Audiense…');
        $data = $this->engine->json($task, Schemas::script(), fake: fn () => Fake::script($reel->headline ?? $reel->title, null, $mode));

        return $this->completeScript($reel, $data, $task, $mode);
    }

    /** Skill roteiro-reels-audiense, seção "Modo de entrega": script a partir da estrutura já entregue. */
    public function fullScript(Reel $reel): array
    {
        $current = $this->currentForPrompt($reel);
        $task = <<<TXT
# Tarefa: escrever o script completo a partir da estrutura (skill roteiro-reels-audiense)
O criador recebeu a estrutura com ideias e agora pediu o script completo. Escreva o texto falado a partir da estrutura abaixo: mesmos pontos, mesma ordem, sem refazer do zero e sem acrescentar fatos novos. Formato 7b: "modo" = "script" e o "texto" de cada bloco é a fala.
{$this->structureRule()}
{$this->limitText()}
Descreva em "mudancas" que o script foi escrito a partir da estrutura. Nas histórias do Segundo Cérebro, use as palavras e os detalhes que o criador escreveu; repita "cerebro_usado" da estrutura.

## Headline (literal)
{$reel->headline}

## Estrutura entregue
{$current}
TXT;
        $this->log('Escrevendo o script completo a partir da estrutura…');
        $data = $this->engine->json($task, Schemas::script(), fake: fn () => Fake::script($reel->headline ?? $reel->title, 'script a partir da estrutura', 'script'));
        $data['modo'] = 'script';

        return $this->completeScript($reel, $data, $task, 'script');
    }

    private function limitText(): string
    {
        $max = Prompts::maxSeconds($this->tenant);

        return 'Limite de duração: até '.Prompts::fmt($max).' de fala (~'.(int) round($max / 60 * 150).' palavras); só passe disso se o conteúdo for realmente denso, com teto de 3 minutos e o motivo em "duracao_motivo". Para caber, encurte os blocos — nunca remova um bloco.';
    }

    private function brainRule(): string
    {
        if (Brain::answers($this->tenant) === []) {
            return 'O criador ainda não preencheu o Segundo Cérebro. Não invente histórias pessoais dele; "cerebro_usado" fica vazio.';
        }

        return 'Use o Segundo Cérebro do system prompt para tirar o roteiro do genérico: escolha a história real do criador que melhor serve a esta headline (dor superada, descoberta, pessoa ajudada ou origem) e encaixe no notável ou na prova; use o método/pilares dele no valor prático; monte a apresentação magnética com nome, título e quem ele ajuda. Conte as histórias com fidelidade, sem acrescentar detalhes. Se nada do Segundo Cérebro tiver ligação real com o tema, não force.';
    }

    private function structureRule(): string
    {
        return 'ESTRUTURA OBRIGATÓRIA — os 11 blocos, todos preenchidos e nesta ordem, sem omitir, juntar ou trocar nenhum: '.Roteiro::orderText().'. Mesmo em história, tese ou argumento, o intensificador de mistério e o CTA de salvamento entram (adapte o conteúdo deles ao tema).';
    }

    /**
     * Converte a resposta em blocos (sempre os 11, na ordem) e garante que nenhum veio vazio.
     * Se algum vier vazio, pede uma correção à IA; se ainda faltar, falha em vez de salvar roteiro incompleto.
     */
    private function completeScript(Reel $reel, array $data, string $task, string $mode): array
    {
        $blocks = Roteiro::toBlocks($data['roteiro'] ?? [], $reel->headline);
        $missing = Roteiro::missing($blocks);

        if ($missing) {
            $labels = implode(', ', array_map(fn ($k) => Roteiro::BLOCKS[$k], $missing));
            $this->log("Faltaram blocos ({$labels}); pedindo para completar…");
            $fix = $this->engine->json(
                $task."\n\n# Correção obrigatória\nNa resposta anterior estes blocos vieram vazios: {$labels}. Devolva o roteiro completo de novo, com os 11 blocos preenchidos, mantendo idênticos os blocos que já estavam bons.\n\n## Resposta anterior\n".self::j($data['roteiro'] ?? []),
                Schemas::script(),
                fake: fn () => Fake::script($reel->headline ?? $reel->title, null, $mode),
            );
            $fixed = Roteiro::toBlocks($fix['roteiro'] ?? [], $reel->headline);
            $blocks = Roteiro::mergeKeepingAll($fixed, $blocks);
            $data = array_merge($data, array_intersect_key($fix, array_flip(['tempo_estimado_segundos', 'duracao_motivo'])));
            if ($still = Roteiro::missing($blocks)) {
                throw new \RuntimeException('A IA não completou a estrutura (faltou: '.implode(', ', array_map(fn ($k) => Roteiro::BLOCKS[$k], $still)).'). Tente gerar de novo.');
            }
        }

        $data['modo'] = $data['modo'] ?? $mode;
        $data['blocos'] = $blocks;
        $data['omitidos'] = [];
        $data['roteiro_limpo'] = $data['modo'] === 'script' ? Roteiro::clean($blocks) : Roteiro::cola($blocks);
        $data['palavras'] = $data['modo'] === 'script' ? self::words($data['roteiro_limpo']) : 0;

        return $data;
    }

    /** Roteiro atual para o prompt. Formato antigo (rótulos fora do padrão) vai inteiro, para ser reorganizado nos 11 blocos. */
    private function currentForPrompt(Reel $reel): string
    {
        $blocks = $reel->script_blocks ?? [];
        if (Roteiro::missing($blocks)) {
            return self::j([
                'estrutura' => $reel->structure,
                'nota' => 'Roteiro no formato antigo: reorganize o conteúdo abaixo nos 11 blocos da estrutura fixa, aproveitando tudo o que servir e escrevendo os blocos que faltam.',
                'blocos_antigos' => $blocks,
            ]);
        }

        return self::j(['estrutura' => $reel->structure, 'roteiro' => Roteiro::fromBlocks($blocks)]);
    }

    private static function words(string $text): int
    {
        return count(preg_split('/\s+/u', trim(preg_replace('/\[NA TELA:[^\]]*\]/u', '', $text)), -1, PREG_SPLIT_NO_EMPTY));
    }

    public function revise(Reel $reel, string $instruction): array
    {
        $current = $this->currentForPrompt($reel);
        $mode = $reel->script_mode === 'script' ? 'script' : 'estrutura';
        $task = <<<TXT
# Tarefa: revisar o roteiro (skill roteiro-reels-audiense)
Aplique a instrução do criador ao roteiro atual. Mantenha o método: princípios obrigatórios e revisão. Não mude a headline, a menos que a instrução peça explicitamente. Mude só o necessário. Descreva em "mudancas" o que mudou.
Modo atual: "{$mode}" — mantenha o mesmo formato (tópicos na estrutura, fala no script), a menos que a instrução peça outro modo; devolva o modo final em "modo".
{$this->structureRule()} Nenhuma instrução de revisão remove um bloco: se pedirem para cortar, encurte o conteúdo do bloco.
{$this->limitText()}
Mantenha as histórias e o método do Segundo Cérebro que já estão no roteiro, a menos que a instrução peça para trocar; atualize "cerebro_usado".

## Instrução do criador
{$instruction}

## Headline
{$reel->headline}

## Tema e anotações
{$reel->theme}
{$this->orNone($reel->notes)}

## Roteiro atual (blocos vazios = faltando, preencha)
{$current}
TXT;
        $this->log('Revisando o roteiro…');
        $data = $this->engine->json($task, Schemas::script(), fake: fn () => Fake::script($reel->headline ?? $reel->title, $instruction, $mode));

        return $this->completeScript($reel, $data, $task, $data['modo'] ?? $mode);
    }

    // ------------------------------------------------------------------
    // Checagem de fatos (reel-completo, etapa 5)
    // ------------------------------------------------------------------

    public function factcheck(Reel $reel): array
    {
        $bible = $this->tenant->profileWithDefaults()['bible_version'] ?: 'ARA';
        $script = Roteiro::missing($reel->script_blocks ?? [])
            ? self::j(['blocos' => $reel->script_blocks])
            : self::j(['roteiro' => Roteiro::fromBlocks($reel->script_blocks ?? [])]);
        $task = <<<TXT
# Etapa: checar fatos (reel-completo, etapa 5)
Confira cada versículo ({$bible}), citação, data e número do roteiro abaixo no texto-fonte, usando a busca na web. Obra de filósofo/autor: abra o texto (domínio público quando der) e cite livro/seção. Se algo não for verificável, corte o FATO do roteiro (nunca o bloco). Histórias e relatos pessoais do criador que vêm do Segundo Cérebro são testemunho dele: não corte nem marque como não verificável; só confira se batem com o que ele escreveu (versículos e dados citados dentro deles são checados normalmente). Música: cite só o título, nunca a letra.
Devolva o roteiro final em "roteiro", com os mesmos 11 blocos (corrigidos, ou idênticos se nada mudou), mantendo o formato (tópicos se for estrutura, fala se for script) e os "segundos". Não mude nada além do que a checagem exigir. A headline nunca muda.
Em "itens", um por fato checado; em "ressalvas", o que o criador precisa saber; em "fontes", os links usados.

## Headline
{$reel->headline}

## Roteiro
{$script}
TXT;
        $this->log('Checando versículos, citações, datas e números…');
        $data = $this->engine->json($task, Schemas::factcheck(), webSearch: true, fake: fn () => Fake::factcheck($reel->script_blocks ?? []));

        // A checagem usa busca na web (resposta sem schema forçado): bloco que sumir volta como estava.
        // Roteiro no formato antigo (importado) não é reescrito pela checagem: só os itens são registrados.
        $legacy = (bool) Roteiro::missing($reel->script_blocks ?? []);
        $blocks = $legacy
            ? ($reel->script_blocks ?? [])
            : Roteiro::mergeKeepingAll(Roteiro::toBlocks($data['roteiro'] ?? [], $reel->headline), $reel->script_blocks ?? []);
        $data['blocos'] = $blocks;
        $data['roteiro_limpo'] = $reel->script_mode === 'script' ? Roteiro::clean($blocks) : Roteiro::cola($blocks);
        $data['palavras'] = $reel->script_mode === 'script' ? self::words($data['roteiro_limpo']) : 0;

        $known = collect($data['fontes'] ?? [])->pluck('url')->all();
        foreach ($this->engine->sourcesList() as $s) {
            if (! in_array($s['url'], $known, true)) {
                $data['fontes'][] = ['titulo' => $s['title'], 'url' => $s['url']];
            }
        }

        return $data;
    }

    /** Versão enxuta da pesquisa para ir nos prompts das etapas seguintes. */
    private function researchForPrompt(array $r): array
    {
        return [
            'resumo' => $r['resumo'] ?? '',
            'assuntos' => collect($r['assuntos'] ?? [])->map(fn ($a) => [
                'assunto' => $a['assunto'] ?? '', 'categoria' => $a['categoria'] ?? '', 'tipo' => $a['tipo'] ?? '',
                'emocao' => $a['emocao'] ?? '', 'evidencia' => $a['evidencia'] ?? '', 'forca' => $a['forca'] ?? null,
                'links' => $a['links'] ?? [],
            ])->all(),
            'top5' => $r['top5'] ?? [],
            'momento' => $r['momento'] ?? [],
        ];
    }
}
