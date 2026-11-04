<?php

namespace App\Http\Controllers;

use App\Ai\Prompts;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    private function tenant(): Tenant
    {
        return auth('api')->user()->tenant;
    }

    private function ensureOwner(): void
    {
        abort_unless(auth('api')->user()->isOwner(), 403, 'Só o dono da conta pode alterar isso.');
    }

    public function show()
    {
        $t = $this->tenant();

        return [
            'workspace' => $t->name,
            'profile' => $t->profileWithDefaults(),
            'ai' => $t->aiSettingsWithDefaults(),
            'keys' => [
                'anthropic' => (bool) $t->anthropic_key,
                'gemini' => (bool) $t->gemini_key,
                'anthropic_env' => (bool) config('reels.anthropic.key') && $t->canUseSharedKeys(),
                'gemini_env' => (bool) config('reels.gemini.key') && $t->canUseSharedKeys(),
            ],
            'fake' => (bool) config('reels.fake'),
            'users' => User::where('tenant_id', $t->id)->get(['id', 'name', 'email', 'role', 'created_at']),
            'skills' => collect(config('reels.skills'))->map(fn ($s) => [
                'name' => $s,
                'size' => strlen(Prompts::skill($s)),
                'updated_at' => is_file(resource_path("prompts/skills/{$s}.md")) ? date('d/m/Y H:i', filemtime(resource_path("prompts/skills/{$s}.md"))) : null,
            ]),
        ];
    }

    public function updateProfile(Request $request)
    {
        $this->ensureOwner();
        $data = $request->validate([
            'workspace' => 'sometimes|string|max:120',
            'profile' => 'sometimes|array',
            'profile.*' => 'nullable|string|max:5000',
        ]);
        $t = $this->tenant();
        if (isset($data['workspace'])) {
            $t->name = $data['workspace'];
        }
        if (isset($data['profile'])) {
            $allowed = array_keys(Tenant::defaultProfile());
            $t->profile = array_merge($t->profile ?? [], array_intersect_key($data['profile'], array_flip($allowed)));
        }
        $t->save();

        return $this->show();
    }

    public function updateAi(Request $request)
    {
        $this->ensureOwner();
        $data = $request->validate([
            'provider' => ['sometimes', Rule::in(['anthropic', 'gemini'])],
            'anthropic_model' => 'sometimes|string|max:60',
            'effort' => ['sometimes', Rule::in(['low', 'medium', 'high', 'xhigh', 'max'])],
            'gemini_model' => 'sometimes|string|max:60',
            'youtube_research' => 'sometimes|boolean',
            'anthropic_key' => 'nullable|string|max:300',
            'gemini_key' => 'nullable|string|max:300',
            'clear_anthropic_key' => 'boolean',
            'clear_gemini_key' => 'boolean',
        ]);
        $t = $this->tenant();
        $t->ai_settings = array_merge($t->ai_settings ?? [], array_intersect_key($data, array_flip(['provider', 'anthropic_model', 'effort', 'gemini_model', 'youtube_research'])));
        if (! empty($data['anthropic_key'])) {
            $t->anthropic_key = trim($data['anthropic_key']);
        }
        if (! empty($data['gemini_key'])) {
            $t->gemini_key = trim($data['gemini_key']);
        }
        if ($data['clear_anthropic_key'] ?? false) {
            $t->anthropic_key = null;
        }
        if ($data['clear_gemini_key'] ?? false) {
            $t->gemini_key = null;
        }
        $t->save();

        return $this->show();
    }

    public function addUser(Request $request)
    {
        $this->ensureOwner();
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'role' => ['sometimes', Rule::in(['owner', 'member'])],
        ]);
        $user = User::create($data + ['tenant_id' => $this->tenant()->id, 'role' => $data['role'] ?? 'member']);

        return response()->json($user, 201);
    }

    public function removeUser(int $id)
    {
        $this->ensureOwner();
        abort_if($id === auth('api')->id(), 422, 'Você não pode remover a si mesmo.');
        User::where('tenant_id', $this->tenant()->id)->where('id', $id)->delete();

        return response()->json(['ok' => true]);
    }

    /** Texto completo de uma skill (para conferir o que a IA recebe). */
    public function skill(string $name)
    {
        abort_unless(in_array($name, config('reels.skills'), true), 404);

        return ['name' => $name, 'content' => Prompts::skill($name)];
    }
}
