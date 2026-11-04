<?php

namespace App\Ai;

use App\Models\AiRun;
use App\Models\Reel;
use App\Models\Tenant;
use Throwable;

/**
 * Fluxo reel-completo: pesquisa viral → top 5 headlines → roteiro → checagem.
 * Cada etapa salva o resultado no Reel ao terminar; se uma falhar, as anteriores ficam.
 */
class ReelPipeline
{
    public const LABELS = [
        'research' => 'Pesquisa viral',
        'headlines' => 'Top 5 headlines',
        'script' => 'Roteiro',
        'factcheck' => 'Checagem de fatos',
    ];

    private Studio $studio;

    public function __construct(private Tenant $tenant, private AiRun $run, private Reel $reel)
    {
        $this->studio = new Studio($tenant, $run);
    }

    /** @param  string[]  $steps */
    public function run(array $steps): void
    {
        $this->reel->update(['status' => 'gerando']);

        foreach ($steps as $step) {
            $this->run->update(['step' => $step]);
            $this->reel->setStep($step, 'running');
            $this->run->appendLog('▶ '.self::LABELS[$step]);
            try {
                $this->{'step'.ucfirst($step)}();
                $this->reel->setStep($step, 'done');
            } catch (Throwable $e) {
                $this->reel->setStep($step, 'error', mb_substr($e->getMessage(), 0, 500));
                $this->reel->update(['status' => $this->reel->script_blocks ? 'roteirizado' : 'rascunho']);
                throw $e;
            }
        }

        $this->reel->refresh();
        $this->reel->update(['status' => $this->reel->script_blocks ? 'roteirizado' : 'rascunho']);
        if ($this->reel->script_blocks && $this->reel->idea && $this->reel->idea->status === 'ideia') {
            $this->reel->idea->update(['status' => 'roteirizado']);
        }
    }

    private function stepResearch(): void
    {
        $round = $this->studio->research($this->reel->title, $this->reel->theme, $this->reel->notes, $this->reel);
        $this->reel->update(['research' => $round->result, 'research_round_id' => $round->id]);
    }

    private function stepHeadlines(): void
    {
        $data = $this->studio->reelHeadlines($this->reel);
        $top = collect($data['top'] ?? [])->sortBy('posicao')->values();
        $data['top'] = $top->all();
        $first = $top->first()['headline'] ?? ($data['headlines'][0]['headline'] ?? null);

        $this->reel->update([
            'headlines' => $data,
            'headline' => $first,
            'reading_note' => $this->mergeNote($data['leitura_adotada'] ?? ''),
        ]);
    }

    private function stepScript(): void
    {
        if (! $this->reel->headline) {
            throw new \RuntimeException('Escolha ou gere uma headline antes do roteiro.');
        }
        $data = $this->studio->script($this->reel);
        $this->applyScript($this->reel, $data);
        $this->reel->update(['reading_note' => $this->mergeNote($data['leitura_adotada'] ?? '')]);
    }

    private function stepFactcheck(): void
    {
        if (! $this->reel->script_blocks) {
            throw new \RuntimeException('Não há roteiro para checar.');
        }
        $data = $this->studio->factcheck($this->reel);
        $this->reel->update([
            'checks' => [
                'itens' => $data['itens'] ?? [],
                'ressalvas' => $data['ressalvas'] ?? [],
                'fontes' => $data['fontes'] ?? [],
                'houve_correcao' => $data['houve_correcao'] ?? false,
                'checado_em' => now()->format('d/m/Y H:i'),
            ],
            'script_blocks' => $data['blocos'] ?: $this->reel->script_blocks,
            'script_clean' => $data['roteiro_limpo'] ?: $this->reel->script_clean,
            'est_seconds' => self::seconds($data['tempo_estimado_segundos'] ?? null, $data['blocos'] ?? []) ?: $this->reel->est_seconds,
            'word_count' => $this->reel->script_mode === 'script' ? ($data['palavras'] ?: $this->reel->word_count) : null,
        ]);
    }

    public static function applyScript(Reel $reel, array $data, bool $keepPreviousStructure = false): void
    {
        $mode = in_array($data['modo'] ?? null, ['estrutura', 'script'], true) ? $data['modo'] : ($reel->script_mode ?: 'estrutura');
        $blocks = $data['blocos'] ?? [];
        $pipeline = $reel->pipeline ?? [];

        // Ao virar script, guarda a estrutura anterior para consulta
        if ($keepPreviousStructure && $reel->script_mode === 'estrutura' && $reel->script_blocks) {
            $pipeline['estrutura_anterior'] = ['blocos' => $reel->script_blocks, 'roteiro_limpo' => $reel->script_clean, 'est_seconds' => $reel->est_seconds];
        }

        $reel->update([
            'script_mode' => $mode,
            'structure' => $data['estrutura'] ?? $reel->structure,
            'script_blocks' => $blocks,
            'script_clean' => $data['roteiro_limpo'] ?? '',
            'omitted' => $data['omitidos'] ?? [],
            'est_seconds' => self::seconds($data['tempo_estimado_segundos'] ?? null, $blocks),
            'word_count' => $mode === 'script' ? ($data['palavras'] ?? null) : null,
        ]);
        $pipeline['meta'] = [
            'estrutura_motivo' => $data['estrutura_motivo'] ?? '',
            'apresentacao_e_sugestao' => $data['apresentacao_e_sugestao'] ?? false,
            'mudancas' => $data['mudancas'] ?? '',
            'duracao_motivo' => $data['duracao_motivo'] ?? '',
            'cerebro_usado' => $data['cerebro_usado'] ?? '',
        ];
        $reel->update(['pipeline' => $pipeline]);
    }

    /** Tempo total: o informado pela IA, ou a soma dos blocos quando ela não informar. */
    public static function seconds(?int $total, array $blocks): ?int
    {
        $sum = (int) collect($blocks)->sum(fn ($b) => (int) ($b['segundos'] ?? 0));

        return $total ?: ($sum ?: null);
    }

    private function mergeNote(string $note): ?string
    {
        $note = trim($note);
        $current = trim((string) $this->reel->reading_note);
        if ($note === '' || str_contains($current, $note)) {
            return $current ?: null;
        }

        return trim($current."\n".$note);
    }
}
