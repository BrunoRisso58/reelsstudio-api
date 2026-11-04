<?php

namespace Database\Seeders;

use App\Models\Idea;
use App\Models\Reel;
use App\Models\ResearchRound;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Cria a conta do Bruno e importa o "Banco de Referências — Reels" (Claude Doc)
 * como estava em 12/10/2026: ideias, sugestões da IA, rodadas e Reels produzidos.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('SEED_EMAIL');
        if (User::where('email', $email)->exists()) {
            $this->command?->warn("Conta {$email} já existe — seed ignorado.");

            return;
        }

        $tenant = Tenant::create(['name' => 'Bruno — Teologia e vida cristã', 'profile' => ['owner_name' => 'Bruno']]);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'role' => 'owner',
            'name' => 'Bruno Risso',
            'email' => $email,
            'password' => env('SEED_PASSWORD'),
        ]);
        TenantContext::set($tenant->id);

        $data = json_decode(file_get_contents(database_path('seeders/data/bruno.json')), true);

        $rounds = [];
        foreach ($data['rounds'] as $r) {
            $round = ResearchRound::create(['scope' => $r['scope'], 'summary' => $r['summary'], 'result' => ['resumo' => $r['summary'], 'importado' => true]]);
            $round->forceFill(['created_at' => Carbon::parse($r['date'].' 12:00')])->save();
            $rounds[$r['key']] = $round;
        }

        $ideas = [];
        foreach ($data['ideas_user'] as $i) {
            $idea = Idea::create([
                'origin' => 'user', 'title' => $i['title'], 'notes' => $i['notes'], 'source' => $i['source'],
                'status' => $i['status'], 'created_by' => $user->id,
            ]);
            $idea->forceFill(['created_at' => Carbon::parse($i['date'].' 10:00')])->save();
            $ideas[$i['title']] = $idea;
        }

        foreach ($data['ideas_ai'] as $i) {
            $round = $rounds[$i['round']];
            $idea = Idea::create([
                'origin' => 'ai', 'title' => $i['title'], 'category' => $i['category'], 'type' => $i['type'],
                'emotion' => $i['emotion'], 'evidence' => $i['evidence'], 'links' => $i['links'],
                'validity' => 'atemporal', 'status' => $i['status'], 'research_round_id' => $round->id,
            ]);
            $idea->forceFill(['created_at' => $round->created_at])->save();
            $ideas[$i['title']] = $idea;
        }

        foreach ($data['reels'] as $r) {
            $idea = $ideas[$r['idea']] ?? null;
            $blocks = $r['script_blocks'] ?? null;
            $reel = Reel::create([
                'idea_id' => $idea?->id,
                'title' => $r['title'],
                'theme' => $r['theme'] ?? $idea?->title ?? $r['title'],
                'notes' => $idea?->notes,
                'headline' => $r['headline'],
                'headlines' => $r['headlines'] ?? null,
                'research_round_id' => $rounds[$r['round']]->id,
                'research' => ['resumo' => $rounds[$r['round']]->summary],
                'structure' => $r['structure'] ?? null,
                'script_blocks' => $blocks,
                'script_clean' => $blocks ? collect($blocks)->pluck('texto')->implode("\n\n") : null,
                'omitted' => $r['omitted'] ?? null,
                'checks' => $r['checks'] ?? null,
                'est_seconds' => $r['est_seconds'] ?? null,
                'word_count' => $r['word_count'] ?? null,
                'reading_note' => $r['reading_note'] ?? null,
                'status' => 'roteirizado',
                'pipeline' => ['research' => ['state' => 'done'], 'headlines' => ['state' => 'done'], 'script' => ['state' => 'done'], 'factcheck' => ['state' => $blocks ? 'done' : 'skipped']],
                'production_notes' => $r['production_notes'] ?? null,
                'created_by' => $user->id,
            ]);
            $reel->forceFill(['created_at' => Carbon::parse($r['date'].' 18:00'), 'updated_at' => Carbon::parse($r['date'].' 18:00')])->save();
        }

        TenantContext::set(null);

        // Banco atualizado em 14/10 (Reels roteirizados até 13/10 + sugestões novas)
        \App\Support\BancoImporter::run($email, database_path('seeders/data/banco-2026-10-14.json'));

        $this->command?->info("Conta criada: {$email} / senha: ".env('SEED_PASSWORD'));
    }
}
