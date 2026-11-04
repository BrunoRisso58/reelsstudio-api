<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** Configuração pública da tela de login. */
    public function config()
    {
        return ['registration' => (bool) config('reels.allow_registration')];
    }

    public function register(Request $request)
    {
        abort_unless(config('reels.allow_registration'), 403, 'O cadastro está fechado. Peça acesso ao dono do app.');
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:190|unique:users,email',
            'password' => 'required|string|min:8',
            'workspace' => 'nullable|string|max:120',
        ]);

        $user = DB::transaction(function () use ($data) {
            $tenant = Tenant::create([
                'name' => ($data['workspace'] ?? null) ?: $data['name'],
                'profile' => ['owner_name' => explode(' ', trim($data['name']))[0]],
            ]);

            return User::create([
                'tenant_id' => $tenant->id,
                'role' => 'owner',
                'name' => $data['name'],
                'email' => strtolower($data['email']),
                'password' => $data['password'],
            ]);
        });

        return $this->tokenResponse(auth('api')->login($user), 201);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        $credentials['email'] = strtolower($credentials['email']);

        if (! $token = auth('api')->attempt($credentials)) {
            throw ValidationException::withMessages(['email' => 'E-mail ou senha incorretos.']);
        }

        return $this->tokenResponse($token);
    }

    public function me()
    {
        $user = auth('api')->user()->load('tenant');

        return response()->json(['user' => $user, 'tenant' => ['id' => $user->tenant->id, 'name' => $user->tenant->name]]);
    }

    public function refresh()
    {
        return $this->tokenResponse(auth('api')->refresh());
    }

    public function logout()
    {
        auth('api')->logout();

        return response()->json(['ok' => true]);
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate(['current' => 'required|string', 'password' => 'required|string|min:8']);
        $user = auth('api')->user();
        if (! password_verify($data['current'], $user->password)) {
            throw ValidationException::withMessages(['current' => 'Senha atual incorreta.']);
        }
        $user->update(['password' => $data['password']]);

        return response()->json(['ok' => true]);
    }

    private function tokenResponse(string $token, int $status = 200)
    {
        $user = auth('api')->setToken($token)->user()->load('tenant');

        return response()->json([
            'token' => $token,
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'user' => $user,
            'tenant' => ['id' => $user->tenant->id, 'name' => $user->tenant->name],
        ], $status);
    }
}
