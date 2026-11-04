<?php

namespace App\Http\Controllers;

use App\Ai\Brain;
use App\Models\AiRun;
use App\Models\Idea;
use App\Models\Reel;
use App\Models\ResearchRound;
use App\Models\ViralRef;
use App\Support\Runs;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Ferramentas avulsas de IA, pesquisas, execuções e painel. */
class StudioController extends Controller
{
    public function headlines(Request $request)
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['A', 'B', 'C'])],
            'topic' => 'nullable|string|max:4000',
            'audience' => 'nullable|string|max:500',
            'format' => ['nullable', Rule::in(['reels', 'carrossel'])],
            'quantity' => 'nullable|integer|min:3|max:30',
            'template' => 'nullable|string|max:500',
            'reference' => 'nullable|string|max:4000',
            'comments' => 'nullable|string|max:4000',
        ]);
        if ($data['mode'] === 'C' && empty($data['reference'])) {
            return response()->json(['message' => 'Na engenharia reversa, cole a headline de referência.'], 422);
        }
        if ($data['mode'] !== 'C' && empty($data['topic']) && empty($data['reference'])) {
            return response()->json(['message' => 'Informe o assunto/tema (ou o vídeo que viralizou).'], 422);
        }

        return response()->json(Runs::start('headlines', $data), 202);
    }

    public function startResearch(Request $request)
    {
        $data = $request->validate(['scope' => 'nullable|string|max:500']);

        return response()->json(Runs::start('research', ['scope' => $data['scope'] ?: 'nicho todo']), 202);
    }

    public function researchIndex()
    {
        return ResearchRound::select('id', 'scope', 'summary', 'reel_id', 'created_at')->withCount('ideas')->latest()->get();
    }

    public function researchShow(ResearchRound $round)
    {
        return $round->load('ideas:id,research_round_id,title,status,origin');
    }

    public function runs(Request $request)
    {
        $q = AiRun::query()->with('reel:id,title');
        if ($type = $request->query('type')) {
            $q->whereIn('type', explode(',', $type));
        }
        if ($request->boolean('active')) {
            $q->whereIn('status', ['queued', 'running']);
        }

        return $q->latest()->limit((int) $request->query('limit', 30))->get();
    }

    public function run(AiRun $run)
    {
        return $run;
    }

    public function dashboard()
    {
        $reelCounts = Reel::selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');
        $ideas = Idea::selectRaw('origin, status, count(*) c')->groupBy('origin', 'status')->get();
        $usage = AiRun::where('created_at', '>=', now()->startOfMonth())
            ->selectRaw('sum(input_tokens) i, sum(output_tokens) o, count(*) n')->first();

        return [
            'reels' => $reelCounts,
            'brain' => Brain::progress(auth('api')->user()->tenant),
            'ideas' => [
                'user_open' => $ideas->where('origin', 'user')->where('status', 'ideia')->sum('c'),
                'ai_open' => $ideas->where('origin', 'ai')->where('status', 'ideia')->sum('c'),
                'total' => $ideas->sum('c'),
            ],
            'refs' => ViralRef::count(),
            'research_rounds' => ResearchRound::count(),
            'usage_month' => ['input_tokens' => (int) $usage->i, 'output_tokens' => (int) $usage->o, 'runs' => (int) $usage->n],
            'next_up' => Reel::select('id', 'title', 'headline', 'status', 'planned_for', 'est_seconds')
                ->whereIn('status', ['roteirizado', 'gravado', 'editado'])
                ->orderByRaw("FIELD(status, 'editado', 'gravado', 'roteirizado')")->orderBy('planned_for')->limit(8)->get(),
            'recent' => Reel::select('id', 'title', 'headline', 'status', 'updated_at')->latest('updated_at')->limit(6)->get(),
            'oldest_idea' => Idea::where('origin', 'user')->where('status', 'ideia')->oldest()->first(['id', 'title']),
        ];
    }
}
