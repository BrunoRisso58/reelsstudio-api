<?php

namespace App\Http\Controllers;

use App\Models\Idea;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IdeaController extends Controller
{
    public function index(Request $request)
    {
        $q = Idea::query()->withCount('reels')->with('researchRound:id,scope,created_at');

        if ($origin = $request->query('origin')) {
            $q->where('origin', $origin);
        }
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        if ($category = $request->query('category')) {
            $q->where('category', $category);
        }
        if ($search = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('title', 'like', "%{$search}%")->orWhere('notes', 'like', "%{$search}%")->orWhere('evidence', 'like', "%{$search}%"));
        }

        return $q->orderByRaw("FIELD(status, 'ideia', 'roteirizado', 'descartado')")->latest()->get();
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['created_by'] = auth('api')->id();
        $data['origin'] ??= 'user';

        return response()->json(Idea::create($data), 201);
    }

    public function show(Idea $idea)
    {
        return $idea->load(['reels:id,idea_id,title,headline,status,created_at', 'researchRound']);
    }

    public function update(Request $request, Idea $idea)
    {
        $idea->update($this->validated($request, true));

        return $idea->fresh();
    }

    public function destroy(Idea $idea)
    {
        $idea->delete();

        return response()->json(['ok' => true]);
    }

    /** Aprovar sugestão da IA: move para as ideias do usuário. */
    public function approve(Idea $idea)
    {
        $idea->update(['origin' => 'user', 'status' => 'ideia']);

        return $idea->fresh();
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => "{$req}|string|max:500",
            'notes' => 'nullable|string',
            'source' => 'nullable|string|max:500',
            'origin' => ['sometimes', Rule::in(['user', 'ai'])],
            'category' => 'nullable|string|max:80',
            'type' => 'nullable|string|max:120',
            'emotion' => 'nullable|string|max:120',
            'evidence' => 'nullable|string',
            'links' => 'nullable|array',
            'validity' => 'nullable|string|max:120',
            'status' => ['sometimes', Rule::in(Idea::STATUSES)],
        ]);
    }
}
