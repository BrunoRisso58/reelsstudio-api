<?php

namespace App\Support;

use App\Models\Idea;
use App\Models\Reel;
use App\Models\ResearchRound;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Importa um export do "Banco de Referências — Reels" (Claude Doc) para a conta
 * de um usuário. Idempotente: pode rodar quantas vezes quiser sem duplicar.
 *
 * - Ideias (suas e da IA): casadas pelo título; as que já existem não são tocadas,
 *   só o status sobe de "ideia" para "roteirizado" quando o banco diz isso.
 * - Rodadas de pesquisa: casadas pelo recorte.
 * - Reels: casados pela headline. Reel existente sem roteiro recebe o conteúdo;
 *   Reel existente com roteiro fica como está (status de gravação é seu).
 */
class BancoImporter
{
    /** @return array<string,int>|null null quando a conta não existe */
    public static function run(string $email, string $file): ?array
    {
        $user = User::where('email', strtolower($email))->first();
        if (! $user || ! is_file($file)) {
            return null;
        }

        $data = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        $previous = TenantContext::id();
        TenantContext::set($user->tenant_id);

        try {
            return (new self($user))->import($data);
        } finally {
            TenantContext::set($previous);
        }
    }

    private array $count = ['ideias' => 0, 'sugestoes' => 0, 'rodadas' => 0, 'reels_novos' => 0, 'reels_completados' => 0, 'reels_mantidos' => 0];

    /** @var array<string, Idea> */
    private array $ideas = [];

    private function __construct(private User $user)
    {
        foreach (Idea::all() as $idea) {
            $this->ideas[self::norm($idea->title)] = $idea;
        }
    }

    private static function norm(?string $s): string
    {
        $s = Str::of((string) $s)->replace(['“', '”', '‘', '’', '"', "'"], '')->ascii()->lower()->squish();

        return (string) $s;
    }

    private function findIdea(string $title): ?Idea
    {
        $key = self::norm($title);
        if (isset($this->ideas[$key])) {
            return $this->ideas[$key];
        }
        // Títulos longos podem ter sido cortados/ajustados: compara o começo
        $prefix = mb_substr($key, 0, 40);
        foreach ($this->ideas as $k => $idea) {
            if (mb_strlen($prefix) >= 12 && str_starts_with($k, $prefix)) {
                return $idea;
            }
        }

        return null;
    }

    private function import(array $data): array
    {
        foreach ($data['ideas_user'] ?? [] as $i) {
            $idea = $this->findIdea($i['title']);
            if (! $idea) {
                $idea = Idea::create([
                    'origin' => 'user', 'title' => mb_substr($i['title'], 0, 500), 'notes' => $i['notes'] ?? null,
                    'source' => isset($i['source']) ? mb_substr($i['source'], 0, 500) : null,
                    'status' => $i['status'] ?? 'ideia', 'created_by' => $this->user->id,
                ]);
                $idea->forceFill(['created_at' => Carbon::parse(($i['date'] ?? now()->toDateString()).' 10:00')])->save();
                $this->ideas[self::norm($idea->title)] = $idea;
                $this->count['ideias']++;
            } else {
                $this->bumpStatus($idea, $i['status'] ?? null);
            }
        }

        $rounds = [];
        foreach ($data['rounds'] ?? [] as $r) {
            $round = ResearchRound::where('scope', mb_substr($r['scope'], 0, 500))->first();
            if (! $round) {
                $round = ResearchRound::create(['scope' => mb_substr($r['scope'], 0, 500), 'summary' => $r['summary'], 'result' => ['resumo' => $r['summary'], 'importado' => true]]);
                $round->forceFill(['created_at' => Carbon::parse($r['date'].' 12:00')])->save();
                $this->count['rodadas']++;
            }
            $rounds[] = $round;

            foreach ($r['ideas'] ?? [] as $i) {
                $idea = $this->findIdea($i['title']);
                if ($idea) {
                    $this->bumpStatus($idea, $i['status'] ?? null);

                    continue;
                }
                $idea = Idea::create([
                    'origin' => 'ai', 'title' => mb_substr($i['title'], 0, 500), 'category' => mb_substr((string) ($i['category'] ?? ''), 0, 80) ?: null,
                    'type' => mb_substr((string) ($i['type'] ?? ''), 0, 120) ?: null, 'emotion' => mb_substr((string) ($i['emotion'] ?? ''), 0, 120) ?: null,
                    'evidence' => $i['evidence'] ?? null, 'links' => $i['links'] ?? [], 'validity' => 'atemporal',
                    'status' => $i['status'] ?? 'ideia', 'research_round_id' => $round->id,
                ]);
                $idea->forceFill(['created_at' => $round->created_at])->save();
                $this->ideas[self::norm($idea->title)] = $idea;
                $this->count['sugestoes']++;
            }
        }

        foreach ($data['reels'] ?? [] as $r) {
            $this->importReel($r, $rounds);
        }

        return $this->count;
    }

    private function bumpStatus(Idea $idea, ?string $status): void
    {
        if ($status === 'roteirizado' && $idea->status === 'ideia') {
            $idea->update(['status' => 'roteirizado']);
        }
    }

    /** @param ResearchRound[] $rounds */
    private function importReel(array $r, array $rounds): void
    {
        $key = self::norm($r['headline']);
        $existing = Reel::all(['id', 'headline', 'script_blocks'])->first(fn ($x) => self::norm($x->headline) === $key);

        if ($existing && $existing->script_blocks) {
            $this->count['reels_mantidos']++;

            return;
        }

        $round = collect($rounds)->first(fn ($x) => str_starts_with(self::norm($x->scope), self::norm($r['round'] ?? '')))
            ?? ResearchRound::all()->first(fn ($x) => str_starts_with(self::norm($x->scope), self::norm($r['round'] ?? '')));
        $idea = ! empty($r['idea']) ? $this->findIdea($r['idea']) : null;
        $blocks = $r['script_blocks'];
        $structureMode = ($r['mode'] ?? 'script') === 'estrutura';

        $clean = $structureMode
            ? collect($blocks)->map(fn ($b) => trim($b['rotulo'].' '.str_replace("\n", ' · ', preg_replace('/^\s*-\s*/m', '', $b['texto']))))->implode("\n")
            : collect($blocks)->pluck('texto')->implode("\n\n");

        $content = [
            'structure' => $r['structure'],
            'script_mode' => $structureMode ? 'estrutura' : 'script',
            'headlines' => $r['headlines'],
            'script_blocks' => $blocks,
            'script_clean' => $clean,
            'omitted' => $r['omitted'],
            'checks' => $r['checks'],
            'est_seconds' => $r['est_seconds'],
            'word_count' => $r['word_count'],
            'reading_note' => $r['reading_note'],
            'research' => $round ? ['resumo' => $round->summary] : null,
            'research_round_id' => $round?->id,
            'pipeline' => ['research' => ['state' => 'done'], 'headlines' => ['state' => 'done'], 'script' => ['state' => 'done'], 'factcheck' => ['state' => 'done'], 'meta' => ['importado' => true]],
        ];

        if ($existing) {
            $existing->update($content + ['production_notes' => 'Doc original: https://claude.ai/code/artifact/'.$r['doc']]);
            $this->count['reels_completados']++;

            return;
        }

        $reel = Reel::create($content + [
            'idea_id' => $idea?->id,
            'title' => $r['title'],
            'theme' => $r['theme'] ?? $idea?->title ?? $r['title'],
            'notes' => $idea?->notes,
            'format' => 'reels',
            'headline' => $r['headline'],
            'status' => 'roteirizado',
            'production_notes' => 'Doc original: https://claude.ai/code/artifact/'.$r['doc'],
            'created_by' => $this->user->id,
        ]);
        $at = Carbon::parse($r['date'].' 18:00');
        $reel->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
        if ($idea) {
            $this->bumpStatus($idea, 'roteirizado');
        }
        $this->count['reels_novos']++;
    }
}
