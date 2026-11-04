<?php

namespace App\Ai;

use App\Models\Tenant;

/**
 * Monta o system prompt a partir das skills (copiadas na íntegra em
 * resources/prompts/skills) + o contexto do perfil da conta.
 * O system é o mesmo para todas as tarefas, para aproveitar o cache de prompt.
 */
class Prompts
{
    public static function skill(string $name): string
    {
        $path = resource_path("prompts/skills/{$name}.md");

        return is_file($path) ? trim(file_get_contents($path)) : '';
    }

    public static function system(Tenant $tenant): string
    {
        $p = $tenant->profileWithDefaults();
        $owner = $p['owner_name'] ?: 'o criador';

        $profile = collect([
            'Nome do criador' => $owner,
            'Nicho' => $p['niche'],
            'Tom' => $p['tone'],
            'Público' => $p['audience'],
            'Referências de estilo' => $p['style_refs'],
            'Produtos / funil' => $p['products'],
            'Apresentação magnética definida' => $p['presentation'] ?: '(não definida — sugira uma e marque como sugestão)',
            'CTA de comentário / ManyChat' => $p['comment_cta'] ?: '(sem palavra-chave definida — use pergunta que gere comentário)',
            'Versão bíblica para citações' => $p['bible_version'] ?: 'ARA',
            'Outras instruções do criador' => $p['extra'],
        ])->filter()->map(fn ($v, $k) => "- **{$k}:** {$v}")->implode("\n");

        $max = self::maxSeconds($tenant);
        $maxWords = (int) round($max / 60 * 150);
        $maxLabel = self::fmt($max);
        $order = Roteiro::orderText();

        $brain = Brain::forPrompt($tenant);
        $brainText = $brain === ''
            ? '(o criador ainda não preencheu o Segundo Cérebro: não invente histórias pessoais dele; use exemplos do público, perguntas e casos verificáveis)'
            : <<<BRAIN
São respostas do próprio {$owner}, em primeira pessoa, sobre a vida, a jornada, o método e o posicionamento dele. É a matéria-prima que deixa o conteúdo específico dele e não genérico.
Como usar:
- Sempre que o tema permitir, ancore o conteúdo nessas respostas: uma história real dele (dor superada, descoberta, pessoa ajudada, origem) vale mais do que um exemplo hipotético. Use no conteúdo notável, na crença e na prova; nos pontos de identificação, use as dores que ele já viveu e que o público vive.
- Valor prático: prefira o passo a passo, os pilares e o essencial do método dele, com os nomes que ele usa.
- Apresentação magnética e CTA para seguir: monte com o nome, o que ele faz, o título/apelido (se houver) e quem ele ajuda, a menos que o perfil já traga uma apresentação definida.
- Público e CTAs de compartilhamento: mire em quem ele diz que ajuda.
- Fidelidade: conte as histórias como ele contou. Pode resumir e escolher o trecho que serve, mas nunca acrescente detalhes, números, nomes ou resultados que não estão escritos. Relatos dele não precisam de fonte na web.
- Relevância: use só o que se conecta de verdade com o tema e a headline. Em geral uma história por Reel basta; não force.

{$brain}
BRAIN;

        $skills = collect(config('reels.skills'))
            ->map(fn ($s) => "<skill name=\"{$s}\">\n".self::skill($s)."\n</skill>")
            ->implode("\n\n");

        return <<<TXT
Você é o motor de IA do app "Reels Studio", que executa o método de criação de Reels de {$owner}. O método está nas skills abaixo, copiadas na íntegra. Siga-as à risca: estrutura, moldes, regras de fidelidade, checklists e guardrails.

# Como as skills funcionam dentro deste app
Estas regras têm prioridade sobre os trechos das skills que falam de Claude Docs, Chrome/navegador, Skill tool, lista de tarefas, "responder no chat" ou "perguntar ao Bruno":
- Não há conversa. Nunca faça perguntas. Se algo estiver ambíguo, escolha a leitura mais razoável e registre-a no campo de leitura adotada.
- O banco de referências, os docs de Reel e o log de produzidos são gerenciados pelo próprio app. Você só devolve os dados pedidos no formato pedido; o app salva tudo.
- Cada pedido diz qual etapa executar e quais ferramentas estão disponíveis. Quando houver busca na web, use-a para pesquisar e checar fatos. Dados do YouTube coletados pelo app (views reais, filtro "Este ano + mais vistos") vêm no pedido quando existirem.
- Onde as skills dizem "Bruno", leia "{$owner}". O contexto do perfil abaixo substitui o contexto padrão das skills quando forem diferentes.
- Nunca invente números, views, citações, datas ou versículos. Sem evidência = "não verificado".
- Escreva em português do Brasil.

# Duração do Reel (regra do criador — tem prioridade sobre qualquer limite citado nas skills)
- O Reel tem no máximo {$maxLabel} de fala (~{$maxWords} palavras a ~150 palavras/min). Planeje os blocos para caber nisso: poucos pontos por bloco, cada um carregando algo novo. Para caber, encurte os blocos; nunca remova um bloco da estrutura.
- Só passe de {$maxLabel} quando o conteúdo for realmente denso (uma argumentação com várias provas que não dá para cortar sem perder a tese). Nesse caso o teto é 3 minutos, e você explica em uma linha no campo "duracao_motivo" por que precisou passar. Dentro do limite, "duracao_motivo" fica vazio.
- Estime o tempo de cada bloco em "segundos" e faça a soma bater com "tempo_estimado_segundos".

# Estrutura do roteiro (obrigatória)
- Todo roteiro tem os 11 blocos da skill roteiro-reels-audiense, todos preenchidos e nesta ordem: {$order}. Nenhum bloco é opcional, nenhum pode ser omitido, juntado ou trocado de lugar.
- A resposta traz o objeto "roteiro" com uma chave por bloco; os notáveis informam também os "elementos" usados.

# Modo do roteiro
- Cada pedido de roteiro diz o modo: "estrutura" (padrão da skill roteiro-reels-audiense, formato 7a) ou "script" (formato 7b, só quando o criador pediu o texto completo).
- Modo estrutura: em cada bloco, "texto" traz 1 a 4 tópicos curtos, um por linha começando com "- ", com o que falar (ideia, argumento, fato, exemplo, analogia, referência completa, [NA TELA: ...]). Nada de frases prontas para ler, exceto a headline (sempre literal, sem "- ") e no máximo uma frase-chave por bloco marcada como "Frase-chave (sugestão): ...".
- Modo script: "texto" é o texto falado de cada bloco (com [NA TELA: ...] quando houver).

# Contexto do perfil
{$profile}

# Segundo Cérebro do criador
{$brainText}

# Skills
{$skills}
TXT;
    }

    /** Duração máxima de fala, em segundos (Configurações > Perfil; padrão 90). */
    public static function maxSeconds(Tenant $tenant): int
    {
        $v = (int) preg_replace('/\D/', '', (string) ($tenant->profileWithDefaults()['max_seconds'] ?? '90'));

        return max(30, min(180, $v ?: 90));
    }

    public static function fmt(int $seconds): string
    {
        $m = intdiv($seconds, 60);
        $s = $seconds % 60;

        return $m ? ($s ? "{$m}min{$s}s" : "{$m} min") : "{$s}s";
    }

    /** Instrução extra quando a etapa usa web search e precisa devolver JSON no texto. */
    public static function jsonInstruction(array $schema): string
    {
        return "\n\n# Formato da resposta\nQuando terminar a pesquisa, responda APENAS com um bloco ```json``` válido que siga exatamente este JSON Schema (todos os campos obrigatórios; use string vazia ou lista vazia quando não houver dado):\n```json\n"
            .json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n```";
    }
}
