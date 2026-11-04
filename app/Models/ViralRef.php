<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ViralRef extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'url', 'platform', 'creator', 'headline', 'views', 'notes', 'template', 'analysis'];

    protected function casts(): array
    {
        return ['analysis' => 'array'];
    }
}
