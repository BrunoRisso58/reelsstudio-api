<?php

return [
    // anthropic | gemini — cada conta pode trocar em Configurações > IA
    'provider' => env('AI_PROVIDER', 'anthropic'),

    // Respostas simuladas (para testar o app sem chamar a API)
    'fake' => (bool) env('AI_FAKE', false),

    // Hospedagem compartilhada: o cron do schedule:run sobe o worker da fila
    'queue_via_scheduler' => (bool) env('QUEUE_VIA_SCHEDULER', false),

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-5-5'),
        'effort' => env('ANTHROPIC_EFFORT', 'medium'),
        'max_tokens' => 32000,
        'web_search_max_uses' => 8,
    ],

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-pro'),
    ],

    // Multi-tenant: quem pode usar as API keys do .env (as outras contas cadastram a própria key em Configurações > IA).
    // Lista de e-mails separados por vírgula; "*" libera para todas as contas.
    'shared_key_emails' => array_values(array_filter(array_map('trim', explode(',', (string) env('AI_SHARED_KEY_EMAILS'))))),

    // Cadastro aberto em /login. Depois que os amigos criarem a conta, ponha false.
    'allow_registration' => (bool) env('ALLOW_REGISTRATION', true),

    // Pasta das skills do Claude Code neste PC (usada por `php artisan skills:sync`)
    'skills_source' => env('SKILLS_SOURCE_DIR'),

    'skills' => ['assuntos-virais-cristao', 'headlines-reels-carrosseis', 'roteiro-reels-audiense', 'reel-completo'],
];
