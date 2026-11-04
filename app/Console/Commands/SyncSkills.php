<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Copia as skills do Claude Code deste PC para resources/prompts/skills,
 * que é o que a IA do app recebe. Rode depois de editar uma skill.
 */
class SyncSkills extends Command
{
    protected $signature = 'skills:sync {path? : Pasta que contém as pastas das skills (cada uma com SKILL.md)}';

    protected $description = 'Atualiza as skills usadas pelo app a partir das skills do Claude Code';

    public function handle(): int
    {
        $dirs = array_filter([$this->argument('path'), config('reels.skills_source')]);

        // Auto-detecta ~/.claude/skills (inclusive as sincronizadas do claude.ai)
        $home = getenv('USERPROFILE') ?: getenv('HOME');
        if ($home) {
            $dirs[] = "{$home}/.claude/skills";
            foreach (glob("{$home}/.claude/skills/synced/*", GLOB_ONLYDIR) ?: [] as $d) {
                $dirs[] = $d;
            }
        }

        $updated = 0;
        foreach (config('reels.skills') as $skill) {
            $source = null;
            foreach ($dirs as $dir) {
                if (is_file("{$dir}/{$skill}/SKILL.md")) {
                    $source = "{$dir}/{$skill}/SKILL.md";
                    break;
                }
            }
            if (! $source) {
                $this->warn("Não encontrei {$skill}/SKILL.md — mantida a versão atual.");

                continue;
            }
            $target = resource_path("prompts/skills/{$skill}.md");
            $new = file_get_contents($source);
            if (is_file($target) && file_get_contents($target) === $new) {
                $this->line("= {$skill} (sem mudanças)");

                continue;
            }
            file_put_contents($target, $new);
            $this->info("✔ {$skill} atualizada ({$source})");
            $updated++;
        }

        $this->line("{$updated} skill(s) atualizada(s). Em produção, suba a pasta resources/prompts/skills.");

        return self::SUCCESS;
    }
}
