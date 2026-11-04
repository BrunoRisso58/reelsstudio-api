<?php

namespace App\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Coleta dados reais do YouTube sem API key: sugestões do autocompletar e
 * busca "Este ano + Contagem de visualizações" (o filtro do método).
 */
class YouTube
{
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130 Safari/537.36';

    // sp=CAMSAggF -> enviados este ano, ordenados por visualizações
    private const FILTER_THIS_YEAR_BY_VIEWS = 'CAMSAggF';

    public function suggestions(string $term, string $lang = 'pt-BR'): array
    {
        try {
            $res = Http::timeout(10)->get('https://suggestqueries.google.com/complete/search', [
                'client' => 'firefox', 'ds' => 'yt', 'hl' => $lang, 'q' => $term,
            ]);
            $data = json_decode($res->body(), true);

            return array_slice($data[1] ?? [], 0, 10);
        } catch (Throwable $e) {
            Log::warning('YouTube suggestions failed: '.$e->getMessage());

            return [];
        }
    }

    /**
     * @return array<int, array{title:string,url:string,channel:string,views:string,views_num:int,published:string}>
     */
    public function search(string $term, string $lang = 'pt-BR', int $limit = 8, bool $thisYearByViews = true): array
    {
        $gl = str_starts_with($lang, 'pt') ? 'BR' : 'US';
        $params = ['search_query' => $term, 'hl' => $lang, 'gl' => $gl];
        if ($thisYearByViews) {
            $params['sp'] = self::FILTER_THIS_YEAR_BY_VIEWS;
        }

        try {
            $html = Http::timeout(20)
                ->withHeaders(['User-Agent' => self::UA, 'Accept-Language' => $lang])
                ->get('https://www.youtube.com/results', $params)
                ->body();
        } catch (Throwable $e) {
            Log::warning('YouTube search failed: '.$e->getMessage());

            return [];
        }

        $data = $this->extractInitialData($html);
        if (! $data) {
            return [];
        }

        $videos = [];
        $sections = $data['contents']['twoColumnSearchResultsRenderer']['primaryContents']['sectionListRenderer']['contents'] ?? [];
        foreach ($sections as $section) {
            foreach ($section['itemSectionRenderer']['contents'] ?? [] as $item) {
                $v = $item['videoRenderer'] ?? null;
                if (! $v || empty($v['videoId'])) {
                    continue;
                }
                $views = $v['viewCountText']['simpleText'] ?? ($v['viewCountText']['runs'][0]['text'] ?? '');
                $videos[] = [
                    'title' => $v['title']['runs'][0]['text'] ?? '',
                    'url' => 'https://youtu.be/'.$v['videoId'],
                    'channel' => $v['ownerText']['runs'][0]['text'] ?? '',
                    'views' => $views,
                    'views_num' => (int) preg_replace('/\D/', '', $views),
                    'published' => $v['publishedTimeText']['simpleText'] ?? '',
                ];
                if (count($videos) >= $limit) {
                    return $videos;
                }
            }
        }

        return $videos;
    }

    private function extractInitialData(string $html): ?array
    {
        $start = strpos($html, 'var ytInitialData = ');
        if ($start === false) {
            return null;
        }
        $start += strlen('var ytInitialData = ');
        $end = strpos($html, ';</script>', $start);
        if ($end === false) {
            return null;
        }

        return json_decode(substr($html, $start, $end - $start), true);
    }

    /**
     * Roda a coleta completa para uma lista de termos e devolve um pacote compacto
     * para ir no prompt (e ser guardado na rodada).
     */
    public function collect(array $termsPt, array $termsEn, int $perTerm = 8): array
    {
        $out = ['coletado_em' => now()->format('d/m/Y H:i'), 'filtro' => 'Este ano + mais vistos', 'buscas' => []];
        foreach (array_slice($termsPt, 0, 6) as $t) {
            $out['buscas'][] = [
                'termo' => $t, 'idioma' => 'pt',
                'autocompletar' => $this->suggestions($t, 'pt-BR'),
                'videos' => $this->search($t, 'pt-BR', $perTerm),
            ];
        }
        foreach (array_slice($termsEn, 0, 4) as $t) {
            $out['buscas'][] = [
                'termo' => $t, 'idioma' => 'en',
                'autocompletar' => $this->suggestions($t, 'en'),
                'videos' => $this->search($t, 'en', $perTerm),
            ];
        }

        return $out;
    }
}
