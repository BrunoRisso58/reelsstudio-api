<?php

namespace App\Ai;

/**
 * JSON Schemas das saídas (structured outputs). Todos os objetos com
 * additionalProperties=false e todos os campos obrigatórios.
 */
class Schemas
{
    private static function obj(array $props): array
    {
        return ['type' => 'object', 'properties' => $props, 'required' => array_keys($props), 'additionalProperties' => false];
    }

    private static function arr(array $items): array
    {
        return ['type' => 'array', 'items' => $items];
    }

    private static function str(?string $description = null): array
    {
        return $description ? ['type' => 'string', 'description' => $description] : ['type' => 'string'];
    }

    public static function searchTerms(): array
    {
        return self::obj([
            'termos_pt' => self::arr(self::str()),
            'termos_en' => self::arr(self::str()),
        ]);
    }

    public static function research(): array
    {
        $link = self::obj(['titulo' => self::str(), 'url' => self::str(), 'views' => self::str('Contagem como vista na fonte, ou "não verificado"')]);

        return self::obj([
            'recorte_adotado' => self::str(),
            'resumo' => self::str('Uma linha: data, recorte e o que não foi verificado nesta rodada'),
            'assuntos' => self::arr(self::obj([
                'assunto' => self::str(),
                'categoria' => ['type' => 'string', 'enum' => ['universal', 'nicho', 'momento']],
                'tipo' => self::str('desejo, dor, medo, crença, hábito, item, pessoa, obra (pode combinar)'),
                'emocao' => self::str('Quente/Fria + qual emoção'),
                'validade' => self::str('atemporal ou prazo estimado'),
                'evidencia' => self::str('Plataforma, views e por que passou na validação'),
                'links' => self::arr($link),
                'por_que_conecta' => self::str(),
                'forca' => ['type' => 'integer', 'description' => '1 a 5'],
            ])),
            'top5' => self::arr(self::obj(['assunto' => self::str(), 'porque' => self::str()])),
            'headlines' => self::arr(self::obj([
                'assunto' => self::str(), 'headline' => self::str(), 'palavra_forte' => self::str(), 'variacao_intensa' => self::str(),
            ])),
            'momento' => self::arr(self::obj(['assunto' => self::str(), 'validade' => self::str(), 'leitura_crista' => self::str()])),
            'descartados' => self::arr(self::obj(['assunto' => self::str(), 'motivo' => self::str()])),
            'fontes' => self::arr(self::str()),
        ]);
    }

    public static function headlines(): array
    {
        return self::obj([
            'leitura_adotada' => self::str('Vazio se não houve ambiguidade'),
            'headlines' => self::arr(self::obj([
                'headline' => self::str(),
                'molde' => self::str('Código do molde, ex.: T7, E3, ou NOVO'),
                'molde_texto' => self::str('O molde copiado da skill'),
                'referencia_original' => self::str(),
                'conteudo' => self::str('O que o vídeo precisa entregar (1 linha)'),
                'intensificador_opcional' => self::str('Só se o template tiver um; senão vazio'),
                'assuntos_virais' => self::arr(self::str()),
                'grupo' => self::str('Agrupamento (ex.: "Mesmo assunto, outros moldes"); vazio se não se aplica'),
            ])),
            'top' => self::arr(self::obj([
                'posicao' => ['type' => 'integer'],
                'headline' => self::str(),
                'molde' => self::str(),
                'referencia_original' => self::str(),
                'entrega' => self::str('O que o vídeo entrega'),
                'porque' => self::str(),
            ])),
            'top3_porque' => self::arr(self::obj(['headline' => self::str(), 'porque' => self::str()])),
            'rebaixadas' => self::arr(self::obj(['headline' => self::str(), 'motivo' => self::str()])),
            'molde_extraido' => self::str('Só na engenharia reversa: o molde com [variáveis]; senão vazio'),
            'molde_correspondente' => self::str('Só na engenharia reversa: código do molde que bate (ou NOVO); senão vazio'),
            'observacoes' => self::str('Notas de carrossel/Reels (onde fica a revelação etc.); pode ser vazio'),
        ]);
    }

    public static function script(): array
    {
        return self::obj([
            'modo' => ['type' => 'string', 'enum' => ['estrutura', 'script']],
            'estrutura' => ['type' => 'string', 'enum' => ['A', 'B', 'C', 'base']],
            'estrutura_motivo' => self::str(),
            'tempo_estimado_segundos' => ['type' => 'integer'],
            'duracao_motivo' => self::str('Vazio se coube no limite; senão, por que o conteúdo denso precisou passar'),
            'leitura_adotada' => self::str('Vazio se não houve ambiguidade'),
            'roteiro' => Roteiro::schema(),
            'apresentacao_e_sugestao' => ['type' => 'boolean', 'description' => 'true se a apresentação magnética foi sugerida pela IA (o perfil não tinha uma)'],
            'mudancas' => self::str('Só em revisões: o que mudou; senão vazio'),
            'cerebro_usado' => self::str('O que do Segundo Cérebro do criador entrou no roteiro e em qual bloco; vazio se nada'),
        ]);
    }

    public static function factcheck(): array
    {
        return self::obj([
            'itens' => self::arr(self::obj([
                'item' => self::str('Versículo, citação, data, número ou obra checada'),
                'status' => ['type' => 'string', 'enum' => ['ok', 'corrigido', 'removido', 'nao_verificavel']],
                'nota' => self::str(),
                'fonte' => self::str('URL ou referência; vazio se não houver'),
            ])),
            'ressalvas' => self::arr(self::str()),
            'fontes' => self::arr(self::obj(['titulo' => self::str(), 'url' => self::str()])),
            'houve_correcao' => ['type' => 'boolean'],
            'roteiro' => Roteiro::schema(),
            'tempo_estimado_segundos' => ['type' => 'integer'],
        ]);
    }
}
