<?php

namespace App\Support;

use App\Jobs\RunAiTask;
use App\Models\AiRun;
use App\Models\Reel;

class Runs
{
    public static function start(string $type, array $input = [], ?Reel $reel = null): AiRun
    {
        $run = AiRun::create([
            'user_id' => auth('api')->id(),
            'type' => $type,
            'status' => 'queued',
            'reel_id' => $reel?->id,
            'input' => $input,
        ]);

        RunAiTask::dispatch($run->id);

        return $run;
    }

    /** Já existe execução ativa para o Reel? */
    public static function activeFor(Reel $reel): ?AiRun
    {
        return AiRun::where('reel_id', $reel->id)->whereIn('status', ['queued', 'running'])->latest()->first();
    }
}
