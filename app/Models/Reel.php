<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reel extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['rascunho', 'gerando', 'roteirizado', 'gravado', 'editado', 'postado', 'descartado'];

    public const STEPS = ['research', 'headlines', 'script', 'factcheck'];

    protected $fillable = [
        'tenant_id', 'idea_id', 'title', 'theme', 'notes', 'format', 'headline', 'headlines', 'research',
        'research_round_id', 'structure', 'script_mode', 'script_blocks', 'script_clean', 'omitted', 'checks', 'est_seconds',
        'word_count', 'reading_note', 'status', 'pipeline', 'planned_for', 'recorded_at', 'posted_at', 'post_url',
        'metrics', 'production_notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'headlines' => 'array',
            'research' => 'array',
            'script_blocks' => 'array',
            'omitted' => 'array',
            'checks' => 'array',
            'pipeline' => 'array',
            'metrics' => 'array',
            'planned_for' => 'date:Y-m-d',
            'recorded_at' => 'datetime',
            'posted_at' => 'datetime',
        ];
    }

    public function idea(): BelongsTo
    {
        return $this->belongsTo(Idea::class);
    }

    public function researchRound(): BelongsTo
    {
        return $this->belongsTo(ResearchRound::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AiRun::class);
    }

    public function setStep(string $step, string $state, ?string $note = null): void
    {
        $pipeline = $this->pipeline ?? [];
        $pipeline[$step] = array_filter(['state' => $state, 'note' => $note, 'at' => now()->toIso8601String()]);
        $this->pipeline = $pipeline;
        $this->save();
    }
}
