<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResearchRound extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'scope', 'summary', 'result', 'youtube', 'reel_id'];

    protected function casts(): array
    {
        return ['result' => 'array', 'youtube' => 'array'];
    }

    public function ideas(): HasMany
    {
        return $this->hasMany(Idea::class);
    }
}
