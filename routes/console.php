<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Hospedagem compartilhada (hPanel) não mantém um worker rodando o tempo todo.
 * Lá, um cron a cada minuto chama `php artisan schedule:run`, que sobe o worker
 * da fila e o deixa processar as gerações de IA pendentes.
 * Na VPS, prefira um worker permanente (supervisor/systemd) — veja DEPLOY.md.
 */
if (config('reels.queue_via_scheduler')) {
    Schedule::command('queue:work --stop-when-empty --max-time=50 --timeout=1500 --tries=1')
        ->everyMinute()
        ->withoutOverlapping(30)
        ->runInBackground();
}

// Execuções que travaram (ex.: processo morto) não ficam "rodando" para sempre
Schedule::call(function () {
    \App\Models\AiRun::withoutGlobalScopes()
        ->whereIn('status', ['queued', 'running'])
        ->where('updated_at', '<', now()->subMinutes(40))
        ->update(['status' => 'error', 'error' => 'Execução interrompida (tempo esgotado). Tente de novo.']);
    \App\Models\Reel::withoutGlobalScopes()
        ->where('status', 'gerando')
        ->where('updated_at', '<', now()->subMinutes(40))
        ->update(['status' => 'rascunho']);
})->everyTenMinutes()->name('limpar-execucoes-travadas')->withoutOverlapping();
