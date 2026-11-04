<?php

use App\Support\BancoImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Traz para a conta do Bruno os Reels roteirizados até 13/10 no Claude
 * (11 no total) e as ideias/sugestões novas do banco. Se a conta ainda não
 * existir (instalação nova), não faz nada: o DatabaseSeeder importa depois.
 */
return new class extends Migration
{
    public function up(): void
    {
        $count = BancoImporter::run(env('SEED_EMAIL', 'brunorisso58@gmail.com'), database_path('seeders/data/banco-2026-10-14.json'));
        if ($count) {
            Log::info('Banco de referências importado', $count);
        }
    }

    public function down(): void
    {
        // Dados importados ficam (podem já ter status de gravação editado).
    }
};
