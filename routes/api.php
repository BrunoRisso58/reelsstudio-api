<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BrainController;
use App\Http\Controllers\IdeaController;
use App\Http\Controllers\ReelController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StudioController;
use App\Http\Controllers\ViralRefController;
use Illuminate\Support\Facades\Route;

Route::get('auth/config', [AuthController::class, 'config']);

Route::middleware('throttle:10,1')->group(function () {
    Route::post('auth/register', [AuthController::class, 'register']);
    Route::post('auth/login', [AuthController::class, 'login']);
});

Route::middleware('auth:api')->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/refresh', [AuthController::class, 'refresh']);
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::post('auth/password', [AuthController::class, 'changePassword']);

    Route::get('dashboard', [StudioController::class, 'dashboard']);

    // Banco de referências
    Route::post('ideas/{idea}/approve', [IdeaController::class, 'approve']);
    Route::apiResource('ideas', IdeaController::class);
    Route::post('refs/{ref}/analyze', [ViralRefController::class, 'analyze']);
    Route::get('refs/{ref}/runs', [ViralRefController::class, 'runs']);
    Route::apiResource('refs', ViralRefController::class)->except('show');

    // Reels
    Route::get('reels/steps', [ReelController::class, 'steps']);
    Route::post('reels/{reel}/generate', [ReelController::class, 'generate']);
    Route::post('reels/{reel}/headline', [ReelController::class, 'chooseHeadline']);
    Route::post('reels/{reel}/revise', [ReelController::class, 'revise']);
    Route::post('reels/{reel}/full-script', [ReelController::class, 'fullScript']);
    Route::apiResource('reels', ReelController::class);

    // Ferramentas de IA
    Route::post('tools/headlines', [StudioController::class, 'headlines']);
    Route::get('research', [StudioController::class, 'researchIndex']);
    Route::post('research', [StudioController::class, 'startResearch']);
    Route::get('research/{round}', [StudioController::class, 'researchShow']);
    Route::get('runs', [StudioController::class, 'runs']);
    Route::get('runs/{run}', [StudioController::class, 'run']);

    // Segundo Cérebro
    Route::get('brain', [BrainController::class, 'show']);
    Route::put('brain', [BrainController::class, 'update']);

    // Configurações
    Route::get('settings', [SettingsController::class, 'show']);
    Route::put('settings/profile', [SettingsController::class, 'updateProfile']);
    Route::put('settings/ai', [SettingsController::class, 'updateAi']);
    Route::post('settings/users', [SettingsController::class, 'addUser']);
    Route::delete('settings/users/{id}', [SettingsController::class, 'removeUser']);
    Route::get('settings/skills/{name}', [SettingsController::class, 'skill']);
});
