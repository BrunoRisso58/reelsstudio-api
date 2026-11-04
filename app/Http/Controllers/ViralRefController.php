<?php

namespace App\Http\Controllers;

use App\Models\AiRun;
use App\Models\ViralRef;
use App\Support\Runs;
use Illuminate\Http\Request;

class ViralRefController extends Controller
{
    public function index(Request $request)
    {
        $q = ViralRef::query();
        if ($search = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('headline', 'like', "%{$search}%")->orWhere('creator', 'like', "%{$search}%")->orWhere('notes', 'like', "%{$search}%"));
        }

        return $q->latest()->get();
    }

    public function store(Request $request)
    {
        $ref = ViralRef::create($this->validated($request));
        if ($request->boolean('analyze')) {
            Runs::start('reverse', ['ref_id' => $ref->id]);
        }

        return response()->json($ref, 201);
    }

    public function update(Request $request, ViralRef $ref)
    {
        $ref->update($this->validated($request, true));

        return $ref->fresh();
    }

    public function destroy(ViralRef $ref)
    {
        $ref->delete();

        return response()->json(['ok' => true]);
    }

    /** Engenharia reversa da headline (skill headlines, modo C). */
    public function analyze(ViralRef $ref)
    {
        $run = Runs::start('reverse', ['ref_id' => $ref->id]);

        return response()->json($run, 202);
    }

    public function runs(ViralRef $ref)
    {
        return AiRun::where('type', 'reverse')->where('input->ref_id', $ref->id)->latest()->limit(5)->get();
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'headline' => "{$req}|string|max:1000",
            'url' => 'nullable|string|max:1000',
            'platform' => 'nullable|string|max:40',
            'creator' => 'nullable|string|max:255',
            'views' => 'nullable|string|max:60',
            'notes' => 'nullable|string',
            'template' => 'nullable|string|max:40',
        ]);
    }
}
