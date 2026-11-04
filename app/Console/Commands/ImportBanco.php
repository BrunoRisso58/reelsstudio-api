<?php

namespace App\Console\Commands;

use App\Support\BancoImporter;
use Illuminate\Console\Command;

class ImportBanco extends Command
{
    protected $signature = 'reels:importar-banco
        {email? : Conta que recebe os dados}
        {--file= : Arquivo JSON (padrão: database/seeders/data/banco-2026-10-14.json)}';

    protected $description = 'Importa o Banco de Referências e os Reels já roteirizados (sem duplicar)';

    public function handle(): int
    {
        $file = $this->option('file') ?: database_path('seeders/data/banco-2026-10-14.json');
        $email = $this->argument('email') ?: env('a');
        $count = BancoImporter::run($email, $file);
        if ($count === null) {
            $this->error('Conta ou arquivo não encontrado.');

            return self::FAILURE;
        }
        foreach ($count as $k => $v) {
            $this->line(str_pad(str_replace('_', ' ', $k), 20).$v);
        }

        return self::SUCCESS;
    }
}
