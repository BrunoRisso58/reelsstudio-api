<?php

namespace App\Jobs;

use App\Ai\ReelPipeline;
use App\Ai\Studio;
use App\Models\AiRun;
use App\Models\Reel;
use App\Models\Tenant;
use App\Models\ViralRef;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunAiTask implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public int $runId) {}

    public function handle(): void
    {
        @set_time_limit(0);

        $run = AiRun::withoutGlobalScopes()->findOrFail($this->runId);
        TenantContext::set($run->tenant_id);
        $tenant = Tenant::findOrFail($run->tenant_id);

        $run->update(['status' => 'running', 'started_at' => now(), 'error' => null]);

        try {
            $output = $this->dispatchRun($run, $tenant);
            $run->update(['status' => 'done', 'output' => $output, 'finished_at' => now(), 'step' => null]);
            $run->appendLog('✔ Concluído');
        } catch (Throwable $e) {
            Log::error("AI run {$run->id} failed: ".$e->getMessage(), ['trace' => $e->getTraceAsString()]);
            $run->update(['status' => 'error', 'error' => mb_substr($e->getMessage(), 0, 2000), 'finished_at' => now()]);
            $run->appendLog('✖ Erro: '.$e->getMessage());
        } finally {
            TenantContext::set(null);
        }
    }

    private function dispatchRun(AiRun $run, Tenant $tenant): ?array
    {
        $in = $run->input ?? [];
        $studio = new Studio($tenant, $run);

        switch ($run->type) {
            case 'reel_pipeline':
                $reel = Reel::findOrFail($run->reel_id);
                (new ReelPipeline($tenant, $run, $reel))->run($in['steps'] ?? array_keys(ReelPipeline::LABELS));

                return ['reel_id' => $reel->id];

            case 'revise':
                $reel = Reel::findOrFail($run->reel_id);
                $reel->update(['status' => 'gerando']);
                try {
                    $data = $studio->revise($reel, $in['instruction'] ?? '');
                    ReelPipeline::applyScript($reel, $data);
                } finally {
                    $reel->update(['status' => $in['previous_status'] ?? 'roteirizado']);
                }

                return ['reel_id' => $reel->id, 'mudancas' => $data['mudancas'] ?? ''];

            case 'full_script':
                $reel = Reel::findOrFail($run->reel_id);
                $reel->update(['status' => 'gerando']);
                try {
                    $data = $studio->fullScript($reel);
                    $data['modo'] = 'script';
                    ReelPipeline::applyScript($reel, $data, keepPreviousStructure: true);
                } finally {
                    $reel->update(['status' => $in['previous_status'] ?? 'roteirizado']);
                }

                return ['reel_id' => $reel->id];

            case 'research':
                $round = $studio->research($in['scope'] ?? 'nicho todo');

                return ['research_round_id' => $round->id, 'summary' => $round->summary];

            case 'headlines':
                return $studio->headlines($in);

            case 'reverse':
                $ref = ViralRef::findOrFail($in['ref_id']);
                $data = $studio->reverse($ref);
                $ref->update(['analysis' => $data, 'template' => mb_substr($data['molde_correspondente'] ?: 'NOVO', 0, 40)]);

                return $data;
        }

        throw new \RuntimeException("Tipo de execução desconhecido: {$run->type}");
    }
}
