<?php

use Illuminate\Support\Facades\Route;

// SPA (build do Vue em public/spa). Qualquer rota que não seja /api cai aqui.
Route::get('/{any?}', function () {
    $index = public_path('spa/index.html');
    if (! is_file($index)) {
        return response('Frontend não compilado. Rode "npm run build" na pasta web.', 503);
    }

    return response()->file($index, ['Cache-Control' => 'no-cache']);
})->where('any', '^(?!api).*$');
