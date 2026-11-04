<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiRun extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'user_id', 'type', 'status', 'reel_id', 'input', 'output', 'step', 'log', 'error',
        'provider', 'model', 'input_tokens', 'output_tokens', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'output' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function reel(): BelongsTo
    {
        return $this->belongsTo(Reel::class);
    }

    public function appendLog(string $line): void
    {
        $this->log = trim(($this->log ?? '')."\n[".now()->format('H:i:s')."] ".$line);
        $this->save();
    }

    public function addUsage(int $in, int $out): void
    {
        $this->input_tokens += $in;
        $this->output_tokens += $out;
        $this->save();
    }
}
