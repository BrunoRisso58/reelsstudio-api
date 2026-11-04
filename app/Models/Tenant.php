<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    protected $fillable = ['name', 'profile', 'brain', 'ai_settings', 'anthropic_key', 'gemini_key'];

    protected $hidden = ['anthropic_key', 'gemini_key'];

    protected function casts(): array
    {
        return [
            'profile' => 'array',
            'brain' => 'array',
            'ai_settings' => 'array',
            'anthropic_key' => 'encrypted',
            'gemini_key' => 'encrypted',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** Esta conta pode usar as API keys do .env? (AI_SHARED_KEY_EMAILS) */
    public function canUseSharedKeys(): bool
    {
        $allowed = config('reels.shared_key_emails', []);
        if (in_array('*', $allowed, true)) {
            return true;
        }

        return $this->users()->whereIn('email', array_map('strtolower', $allowed))->exists();
    }

    /** Perfil com os valores padrão preenchidos. */
    public function profileWithDefaults(): array
    {
        return array_merge(self::defaultProfile(), array_filter($this->profile ?? [], fn ($v) => $v !== null && $v !== ''));
    }

    public function aiSettingsWithDefaults(): array
    {
        return array_merge([
            'provider' => config('reels.provider'),
            'anthropic_model' => config('reels.anthropic.model'),
            'effort' => config('reels.anthropic.effort'),
            'gemini_model' => config('reels.gemini.model'),
            'youtube_research' => true,
        ], array_filter($this->ai_settings ?? [], fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * Contexto padrão = o perfil descrito nas skills (teologia e vida cristã).
     * Outras contas podem sobrescrever tudo em Configurações > Perfil.
     */
    public static function defaultProfile(): array
    {
        return [
            'owner_name' => 'Bruno',
            'niche' => 'teologia e vida cristã',
            'tone' => 'Tom leve mas sério, poucos memes, equilibra profundidade teológica com linguagem pastoral e próxima.',
            'audience' => 'Cristãos brasileiros; públicos naturais: adolescentes/jovens cristãos e pais de adolescentes (atua no ministério de adolescentes).',
            'style_refs' => 'Referências brasileiras de estilo: JP Maia, Roberta Vicente. Prefere referências estrangeiras para inspiração.',
            'products' => 'Produtos futuros: app de devocional (SaaS) e ebook — assuntos ligados a devocional, leitura bíblica e oração têm valor duplo (alcance + funil).',
            'presentation' => '',
            'comment_cta' => '',
            'bible_version' => 'ARA',
            'max_seconds' => '90',
            'extra' => '',
        ];
    }
}
