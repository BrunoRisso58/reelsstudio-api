<?php

namespace App\Http\Controllers;

use App\Ai\Brain;
use App\Models\Tenant;
use Illuminate\Http\Request;

class BrainController extends Controller
{
    private function tenant(): Tenant
    {
        return auth('api')->user()->tenant;
    }

    public function show()
    {
        $t = $this->tenant();

        return [
            'sections' => Brain::SECTIONS,
            'answers' => (object) Brain::answers($t),
            'progress' => Brain::progress($t),
            'updated_at' => $t->brain['_updated_at'] ?? null,
        ];
    }

    /** Salva uma ou mais respostas (as que não vierem ficam como estão). */
    public function update(Request $request)
    {
        abort_unless(auth('api')->user()->isOwner(), 403, 'Só o dono da conta pode alterar o Segundo Cérebro.');
        $data = $request->validate([
            'answers' => 'required|array',
            'answers.*' => 'nullable|string|max:10000',
        ]);
        $t = $this->tenant();
        $new = array_intersect_key($data['answers'], array_flip(Brain::keys()));
        $t->brain = array_merge($t->brain ?? [], array_map(fn ($v) => trim((string) $v), $new), ['_updated_at' => now()->toIso8601String()]);
        $t->save();

        return $this->show();
    }
}
