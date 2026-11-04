<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Idea extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['ideia', 'roteirizado', 'descartado'];

    protected $fillable = [
        'tenant_id', 'origin', 'title', 'notes', 'source', 'category', 'type', 'emotion',
        'evidence', 'links', 'validity', 'status', 'research_round_id', 'created_by',
    ];

    protected function casts(): array
    {
        return ['links' => 'array'];
    }

    public function reels(): HasMany
    {
        return $this->hasMany(Reel::class);
    }

    public function researchRound(): BelongsTo
    {
        return $this->belongsTo(ResearchRound::class);
    }
}
