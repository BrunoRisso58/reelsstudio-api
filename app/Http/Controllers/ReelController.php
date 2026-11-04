<?php

namespace App\Http\Controllers;

use App\Ai\ReelPipeline;
use App\Models\AiRun;
use App\Models\Idea;
use App\Models\Reel;
use App\Support\Runs;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ReelController extends Controller
{
    private const LIST_COLUMNS = ['id', 'idea_id', 'title', 'headline', 'status', 'structure', 'script_mode', 'est_seconds', 'pipeline', 'planned_for', 'recorded_at', 'posted_at', 'post_url', 'metrics', 'format', 'created_at', 'updated_at'];

    public function index(Request $request)
    {
        $q = Reel::query()->select(self::LIST_COLUMNS);
        if ($status = $request->query('status')) {
            $q->whereIn('status', explode(',', $status));
        }
        if ($search = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('title', 'like', "%{$search}%")->orWhere('headline', 'like', "%{$search}%")->orWhere('theme', 'like', "%{$search}%"));
        }

        return $q->latest()->get();
    }

    /**
     * Novo Reel. Fluxo reel-completo etapa 1: se o tema veio digitado, entra no
     * banco como ideia do usuário exatamente como veio (sem reescrever).
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'idea_id' => 'nullable|integer',
            'theme' => 'required_without:idea_id|nullable|string',
            'notes' => 'nullable|string',
            'source' => 'nullable|string|max:500',
            'title' => 'nullable|string|max:255',
            'headline' => 'nullable|string|max:1000',
            'format' => ['nullable', Rule::in(['reels', 'carrossel'])],
            'script_mode' => ['nullable', Rule::in(['estrutura', 'script'])],
            'generate' => 'boolean',
            'steps' => 'nullable|array',
            'steps.*' => [Rule::in(Reel::STEPS)],
            'save_idea' => 'boolean',
        ]);

        $idea = null;
        if (! empty($data['idea_id'])) {
            $idea = Idea::findOrFail($data['idea_id']);
        } elseif ($data['save_idea'] ?? true) {
            $existing = Idea::where('title', $data['theme'])->first();
            $idea = $existing ?: Idea::create([
                'origin' => 'user',
                'title' => mb_substr($data['theme'], 0, 500),
                'notes' => $data['notes'] ?? null,
                'source' => $data['source'] ?? null,
                'status' => 'ideia',
                'created_by' => auth('api')->id(),
            ]);
        }

        $theme = $data['theme'] ?? $idea->title;
        $notes = $data['notes'] ?? $idea?->notes;
        if ($idea && $idea->source && ! str_contains((string) $notes, $idea->source)) {
            $notes = trim($notes."\nFonte: ".$idea->source);
        }

        $reel = Reel::create([
            'idea_id' => $idea?->id,
            'title' => $data['title'] ?? Str::limit($theme, 80, '…'),
            'theme' => $theme,
            'notes' => $notes,
            'format' => $data['format'] ?? 'reels',
            'script_mode' => $data['script_mode'] ?? 'estrutura',
            'headline' => $data['headline'] ?? null,
            'status' => 'rascunho',
            'created_by' => auth('api')->id(),
        ]);

        $run = null;
        if ($data['generate'] ?? true) {
            $steps = $data['steps'] ?? (! empty($data['headline']) ? ['research', 'script', 'factcheck'] : Reel::STEPS);
            $run = Runs::start('reel_pipeline', ['steps' => array_values(array_intersect(Reel::STEPS, $steps))], $reel);
            $reel->update(['status' => 'gerando']);
        }

        return response()->json(['reel' => $reel->fresh(), 'run' => $run], 201);
    }

    public function show(Reel $reel)
    {
        $reel->load(['idea:id,title,origin,status', 'researchRound:id,scope,summary,created_at']);
        $reel->setAttribute('active_run', Runs::activeFor($reel));
        $reel->setAttribute('last_run', AiRun::where('reel_id', $reel->id)->latest()->first());

        return $reel;
    }

    public function update(Request $request, Reel $reel)
    {
        $data = $request->validate([
            'title' => 'sometimes|string|max:255',
            'theme' => 'sometimes|string',
            'notes' => 'nullable|string',
            'format' => ['sometimes', Rule::in(['reels', 'carrossel'])],
            'headline' => 'nullable|string|max:1000',
            'script_blocks' => 'nullable|array',
            'script_blocks.*.rotulo' => 'required|string',
            'script_blocks.*.texto' => 'nullable|string',
            'script_blocks.*.segundos' => 'nullable|integer|min:0|max:600',
            'script_clean' => 'nullable|string',
            'status' => ['sometimes', Rule::in(Reel::STATUSES)],
            'planned_for' => 'nullable|date',
            'recorded_at' => 'nullable|date',
            'posted_at' => 'nullable|date',
            'post_url' => 'nullable|string|max:1000',
            'metrics' => 'nullable|array',
            'production_notes' => 'nullable|string',
        ]);

        // Datas automáticas ao mover no quadro
        if (isset($data['status'])) {
            if ($data['status'] === 'gravado' && ! $reel->recorded_at && ! array_key_exists('recorded_at', $data)) {
                $data['recorded_at'] = now();
            }
            if ($data['status'] === 'postado' && ! $reel->posted_at && ! array_key_exists('posted_at', $data)) {
                $data['posted_at'] = now();
            }
        }

        // Roteiro editado à mão: recalcula o limpo (ou a cola) se não veio junto
        $isStructure = $reel->script_mode === 'estrutura';
        if (isset($data['script_blocks'])) {
            $data['script_blocks'] = collect($data['script_blocks'])->map(fn ($b) => array_filter([
                'rotulo' => $b['rotulo'], 'texto' => $b['texto'] ?? '', 'segundos' => isset($b['segundos']) ? (int) $b['segundos'] : null,
            ], fn ($v) => $v !== null))->all();
            if (! array_key_exists('script_clean', $data)) {
                $data['script_clean'] = $isStructure
                    ? collect($data['script_blocks'])->map(fn ($b) => trim($b['rotulo'].' '.str_replace("\n", ' · ', preg_replace('/^\s*-\s*/m', '', trim($b['texto'])))))->implode("\n")
                    : collect($data['script_blocks'])->pluck('texto')->filter()->implode("\n\n");
            }
            if ($isStructure) {
                $data['est_seconds'] = ReelPipeline::seconds(null, $data['script_blocks']) ?? $reel->est_seconds;
            }
        }
        if (isset($data['script_clean']) && ! $isStructure) {
            $words = str_word_count(strip_tags(preg_replace('/\[NA TELA:[^\]]*\]/u', '', $data['script_clean'])), 0, 'ÀÁÂÃÇÉÊÍÓÔÕÚàáâãçéêíóôõú');
            $data['word_count'] = $words;
            $data['est_seconds'] = (int) round($words / 150 * 60);
        }

        $reel->update($data);

        return $this->show($reel->fresh());
    }

    public function destroy(Reel $reel)
    {
        $reel->delete();

        return response()->json(['ok' => true]);
    }

    /** (Re)gera etapas do fluxo. Ex.: {"steps":["script","factcheck"]} */
    public function generate(Request $request, Reel $reel)
    {
        $data = $request->validate(['steps' => 'required|array|min:1', 'steps.*' => [Rule::in(Reel::STEPS)]]);
        if ($active = Runs::activeFor($reel)) {
            return response()->json(['message' => 'Já existe uma geração em andamento para este Reel.', 'run' => $active], 409);
        }
        $run = Runs::start('reel_pipeline', ['steps' => array_values(array_intersect(Reel::STEPS, $data['steps']))], $reel);
        $reel->update(['status' => 'gerando']);

        return response()->json($run, 202);
    }

    /** Escolhe outra headline do top 5 e (opcionalmente) refaz roteiro + checagem. */
    public function chooseHeadline(Request $request, Reel $reel)
    {
        $data = $request->validate(['headline' => 'required|string|max:1000', 'regenerate' => 'boolean']);
        $reel->update(['headline' => $data['headline']]);

        $run = null;
        if ($data['regenerate'] ?? true) {
            if (Runs::activeFor($reel)) {
                return response()->json(['message' => 'Já existe uma geração em andamento para este Reel.'], 409);
            }
            $run = Runs::start('reel_pipeline', ['steps' => ['script', 'factcheck']], $reel);
            $reel->update(['status' => 'gerando']);
        }

        return response()->json(['reel' => $reel->fresh(), 'run' => $run]);
    }

    /** Estrutura → script completo (mesmos pontos, mesma ordem). */
    public function fullScript(Reel $reel)
    {
        if (! $reel->script_blocks) {
            return response()->json(['message' => 'Este Reel ainda não tem roteiro.'], 422);
        }
        if (Runs::activeFor($reel)) {
            return response()->json(['message' => 'Já existe uma geração em andamento para este Reel.'], 409);
        }
        $run = Runs::start('full_script', ['previous_status' => $reel->status], $reel);

        return response()->json($run, 202);
    }

    public function revise(Request $request, Reel $reel)
    {
        $data = $request->validate(['instruction' => 'required|string|max:4000']);
        if (! $reel->script_blocks) {
            return response()->json(['message' => 'Este Reel ainda não tem roteiro.'], 422);
        }
        if (Runs::activeFor($reel)) {
            return response()->json(['message' => 'Já existe uma geração em andamento para este Reel.'], 409);
        }
        $run = Runs::start('revise', ['instruction' => $data['instruction'], 'previous_status' => $reel->status], $reel);

        return response()->json($run, 202);
    }

    public function steps()
    {
        return ReelPipeline::LABELS;
    }
}
