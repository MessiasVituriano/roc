<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\Question;
use App\Services\EventFlowService;
use Illuminate\Database\Seeder;

/**
 * Conteúdo da dinâmica, transcrito do PDF "Sala de Decisão ROC — Perguntas,
 * Alternativas e Pontuação".
 *
 * A régua de pontos é fixa nas cinco rodadas da Fase 1: +150 (melhor decisão
 * para o hotel), +80, 0 e -50. Trocar o conteúdo do evento é editar este
 * arquivo.
 *
 * A Fase 2 é **uma rodada só** e não tem alternativas: todas as mesas recebem a
 * mesma missão final, decidem livremente por consenso e o facilitador lança a
 * pontuação mesa a mesa pelo painel (`manual_scoring`).
 */
class LiveConsensusSeeder extends Seeder
{
    /**
     * Tempo de cada uma das cinco rodadas da Fase 1.
     *
     * Um minuto: o suficiente para ler o cenário do hotel sem pressa, pesar as
     * quatro alternativas e ainda trocar de ideia — a escolha pode ser mudada
     * enquanto o cronômetro corre. O facilitador corta antes com ⏹ Encerrar
     * quando a sala já fechou, e estica com +10s / +30s quando não fechou.
     */
    public const PHASE_ONE_DURATION = 60;

    /**
     * Tempo das rodadas da Fase 2 — a rodada final e o desempate.
     *
     * O dobro da Fase 1 porque aqui a mesa precisa conversar antes de decidir:
     * não é uma escolha individual, é um consenso a construir entre quatro
     * missões que se contradizem. Vale para as duas perguntas da fase; o
     * facilitador ajusta ao vivo pelos mesmos controles.
     */
    public const PHASE_TWO_DURATION = 120;

    protected array $missions = [
        ['diaria_media', 'Diária Média', 'Seu diretor financeiro pediu que você preservasse a diária média.', '💰', '#c9922e'],
        ['participacao', 'Participação de Mercado', 'O hotel precisa recuperar participação de mercado.', '💼', '#3b82f6'],
        ['reservas_diretas', 'Reservas Diretas', 'A diretoria quer reduzir a dependência de comissão de OTAs.', '📣', '#a855f7'],
        ['ocupacao', 'Ocupação', 'O proprietário quer ocupação plena no período.', '🔗', '#10b981'],
    ];

    protected array $identities = [
        ['🚀', 'Apollo', '#6366f1'],
        ['🦁', 'Leões', '#f59e0b'],
        ['🐯', 'Tigres', '#f97316'],
        ['🌊', 'Oceano', '#06b6d4'],
        ['⭐', 'Estrelas', '#eab308'],
        ['🔥', 'Fênix', '#ef4444'],
        ['🌪️', 'Ciclone', '#0ea5e9'],
        ['🌵', 'Cactos', '#22c55e'],
        ['🦅', 'Águias', '#8b5cf6'],
        ['🐺', 'Lobos', '#64748b'],
        ['🍀', 'Trevos', '#16a34a'],
        ['⚡', 'Raios', '#facc15'],
        ['🐬', 'Golfinhos', '#38bdf8'],
        ['🎯', 'Alvo', '#e11d48'],
        ['🧭', 'Bússola', '#14b8a6'],
        ['🛡️', 'Escudos', '#7c3aed'],
        ['🌋', 'Vulcão', '#dc2626'],
        ['🎸', 'Rock', '#db2777'],
        ['🧩', 'Enigma', '#a855f7'],
        ['🏔️', 'Everest', '#0891b2'],
    ];

    public function run(): void
    {
        $event = Event::create([
            'title' => 'Sala de Decisões ROC',
            'status' => Event::STATUS_DRAFT,
            'phase' => 1,
            'current_round' => 1,
            'round_status' => Event::ROUND_IDLE,
            'round_duration' => self::PHASE_ONE_DURATION,
        ]);

        foreach ($this->missions as [$key, $name, $statement, $icon, $color]) {
            $event->missions()->create(compact('key', 'name', 'statement', 'icon', 'color'));
        }

        foreach ($this->identities as $index => [$icon, $name, $color]) {
            $event->tables()->create([
                'name' => $name,
                'icon' => $icon,
                'color' => $color,
                // 5 colunas x 4 fileiras, em percentuais do mapa
                'position_x' => 12 + ($index % 5) * 19,
                'position_y' => 20 + intdiv($index, 5) * 21,
            ]);
        }

        $this->syncQuestions($event);
    }

    /**
     * (Re)cria as perguntas do evento a partir deste arquivo.
     *
     * É o caminho para carregar conteúdo novo sem resetar o evento inteiro: as
     * pessoas, as mesas e o layout ficam onde estão. Só é seguro enquanto
     * ninguém votou — apagar uma pergunta apaga os votos dela em cascata —, e
     * quem garante isso é quem chama.
     *
     * @return int quantas perguntas ficaram cadastradas
     */
    public function syncQuestions(Event $event): int
    {
        $event->questions()->delete();

        foreach ($this->rounds() as $round) {
            $question = $event->questions()->create([
                'phase' => $round['phase'],
                'round' => $round['round'],
                'mode' => $round['mode'],
                'label' => $round['label'],
                'title' => $round['title'],
                'context' => $round['context'],
                'bias_note' => $round['bias'] ?? null,
                'duration' => $round['duration'],
                'is_bonus' => $round['is_bonus'] ?? false,
                'manual_scoring' => $round['manual_scoring'] ?? false,
                'scenario_key' => $round['key'] ?? null,
                'source' => $round['source'] ?? null,
                'active' => $round['active'] ?? true,
            ]);

            foreach ($round['options'] as $order => [$text, $effect, $points]) {
                $question->options()->create([
                    'text' => $text,
                    'effect' => $effect,
                    'points' => $points,
                    'color' => $this->colorForPoints($points),
                    'order' => $order,
                ]);
            }
        }

        // as rodadas nascem na ordem de cadastro e são renumeradas para que as
        // ativas fiquem contíguas — a mesma rotina que roda quando o
        // facilitador troca a seleção pelo painel
        app(EventFlowService::class)->renumberRounds($event);

        // a rodada corrente volta para a primeira ativa: a que estava apontada
        // pode nem existir mais depois de recarregar o conteúdo
        $event->update([
            'current_round' => 1,
            'current_question_id' => $event->questions()
                ->where('phase', 1)->where('active', true)->orderBy('round')->value('id'),
        ]);

        return $event->questions()->count();
    }

    /** Verde para a melhor decisão, vermelho para a que destrói valor. */
    protected function colorForPoints(int $points): string
    {
        return match (true) {
            $points >= 150 => '#22c55e',
            $points > 0 => '#3b82f6',
            $points === 0 => '#94a3b8',
            default => '#ef4444',
        };
    }

    /**
     * O roteiro inteiro, na ordem em que o facilitador o percorre: os cinco
     * cenários individuais, os mesmos cinco em mesa, a rodada final e o
     * desempate.
     */
    protected function rounds(): array
    {
        // Os ativos primeiro: `round` precisa ser contíguo a partir de 1 para
        // as ativas, porque é por ele que o evento anda pelas rodadas.
        $scenarios = collect($this->scenarios())
            ->sortByDesc(fn (array $s) => ($s['active'] ?? true) ? 1 : 0)
            ->values()
            ->all();

        return [
            // Fase 1 — cada pessoa decide sozinha, com a missão que sorteou.
            ...array_map(
                fn (array $scenario, int $i) => $scenario + [
                    'phase' => 1,
                    'round' => $i + 1,
                    'mode' => Question::MODE_INDIVIDUAL,
                    'duration' => self::PHASE_ONE_DURATION,
                ],
                $scenarios,
                array_keys($scenarios),
            ),

            // Fase 2 — os mesmos cenários, agora decididos pela mesa.
            ...array_map(
                fn (array $scenario, int $i) => $scenario + [
                    'phase' => 2,
                    'round' => $i + 1,
                    'mode' => Question::MODE_CONSENSUS,
                    'duration' => self::PHASE_TWO_DURATION,
                ],
                $scenarios,
                array_keys($scenarios),
            ),

            ...$this->closingRounds(count($scenarios)),
        ];
    }

    /**
     * Os cinco cenários do PDF, sem fase: cada um é jogado **duas vezes** — na
     * Fase 1 por cada pessoa, puxada pela missão que sorteou, e na Fase 2 pela
     * mesa inteira, em consenso.
     *
     * A simetria é o ponto. Mesma pergunta, mesma régua, decisor diferente: é
     * o que transforma o comparativo do fecho numa medida em vez de uma
     * impressão — a mesa não é comparada com outra coisa, é comparada com as
     * mesmas pessoas decidindo sozinhas meia hora antes.
     */
    protected function scenarios(): array
    {
        return [...$this->scenariosSp2026(), ...$this->scenariosOriginais()];
    }

    /**
     * Os cinco cenários do PDF "Sala de Decisões ROC SP 2026 — Versão Final".
     *
     * Ativos por padrão: é o conteúdo que o evento vai jogar. Os originais
     * continuam cadastrados, desligados, e o painel troca de conjunto sem
     * precisar de deploy.
     *
     * A ordem das alternativas é a do PDF, embaralhada de propósito: a de +150
     * cai em A, B, C ou D dependendo da rodada, para a posição não virar pista.
     */
    protected function scenariosSp2026(): array
    {
        return [
            [
                'key' => 'sp2026-rms',
                'source' => 'roc-sp-2026',
                'label' => 'A IA SUGERIU, VOCÊ DECIDE',
                'title' => 'O que você faz?',
                'context' => 'Seu RMS sugere +18% na tarifa para o próximo fim de semana. Hotel com 78% de ocupação. Porém, um evento corporativo que representa 60% do pickup foi cancelado há 2 horas. O sistema ainda não processou os cancelamentos.',
                'bias' => 'Os dois melhores ignoram o RMS: o que separa +150 de +80 é confirmar o estrago antes de queimar tarifa.',
                'options' => [
                    ['Ignoro o aumento, mantenho tarifa e monitoro. Se cancelamentos vierem, entro com promo last minute em 1-2 canais de retorno rápido.', 'Corrige a máquina com inteligência humana, não entra em pânico, e tem plano B cirúrgico pronto.', 150],
                    ['Sigo o RMS. O sistema tem mais dados que eu e geralmente acerta.', 'Confia na máquina sem considerar informação que ela ainda não tem.', 0],
                    ['Aplico o aumento agora e, se cancelarem, reduzo depois para preencher.', 'Sobe e desce tarifa em 48h. Confunde o mercado e perde nos dois movimentos.', -50],
                    ['Ignoro o aumento e já abro promoções em todos os canais para antecipar a queda.', 'Reage rápido, mas abre promo antes de confirmar o tamanho do estrago. Pode queimar tarifa sem necessidade.', 80],
                ],
            ],
            [
                'key' => 'sp2026-concorrente',
                'source' => 'roc-sp-2026',
                'label' => 'O CONCORRENTE DESESPERADO',
                'title' => 'O que você faz?',
                'context' => 'Hotel 5 estrelas, 72% de ocupação, diária média R$ 890. Concorrente direto derrubou tarifa em 35% para o feriado (daqui a 18 dias). Comercial pressiona para reagir. Pickup estável.',
                'bias' => 'Os 18 dias são a régua: o que separa +150 de +80 é o que dá para maturar nesse prazo.',
                'options' => [
                    ['Mantenho tarifa e monitoro pickup por 7 dias. Se não evoluir, crio pacote.', 'Perde 7 dos 18 dias sem agir. Quando decidir, a janela já encolheu.', 0],
                    ['Reduzo 30% para ficar competitivo. Não posso perder o feriado.', 'Entra na espiral de commoditização, destrói diária média e posicionamento.', -50],
                    ['Mantenho tarifa, trabalho upsell com base de clientes, ofereço MAP, fecho canais de menor rentabilidade.', 'Ação imediata, viável em 18 dias: rentabiliza sem reduzir preço, usa base própria.', 150],
                    ['Mantenho tarifa e aciono marketing para campanhas de valor agregado.', 'Direção correta, mas campanhas de marketing precisam de tempo — 18 dias é curto para maturar.', 80],
                ],
            ],
            [
                'key' => 'sp2026-contrato',
                'source' => 'roc-sp-2026',
                'label' => 'O CONTRATO QUE VENCEU',
                'title' => 'Como conduz a renovação?',
                'context' => 'Maior contrato corporativo (420 RN/mês) em renovação. Empresa pede -12% na tarifa. Seu dado: volume real caiu para 290 RN/mês nos últimos 6 meses. Tarifa atual já está 25% abaixo da BAR. Comercial quer renovar "para não perder".',
                'bias' => 'O dado do volume real é a chave: quem não o usa cede preço sobre quartos que o cliente já não compra.',
                'options' => [
                    ['Aceito -5% mantendo 420 RN contratadas com penalidade se não atingir 80%.', 'Parece equilibrado, mas cede preço sobre volume que o cliente já não entrega.', 80],
                    ['Renovo sem redução, ajusto volume para o real (290 RN). Cláusula: se voltarem a 420, ganham 5% progressivo.', 'Usa dados reais, não cede preço, mas oferece caminho para o cliente ganhar se entregar volume.', 150],
                    ['Aceito os -12%. Perder 18% da ocupação seria catastrófico.', 'Cede preço sobre volume que já caiu. Vai receber menos por menos quartos. Dupla perda.', -50],
                    ['Peço 15 dias para analisar histórico e montar contraproposta.', 'O cliente tem outras propostas. 15 dias é tempo demais — pode fechar com concorrente.', 0],
                ],
            ],
            [
                'key' => 'sp2026-upsell',
                'source' => 'roc-sp-2026',
                'label' => 'O DILEMA DO UPSELL',
                'title' => 'Como resolve?',
                'context' => 'Hotel 200 UHs, 94% de ocupação amanhã. Restam 4 standards e 8 suítes. Cliente fidelizado (15 estadias/ano) pede standard a R$ 580 (tarifa acordo). Há demanda orgânica para suítes a R$ 1.200 no site.',
                'bias' => 'Todas honram ou quebram a tarifa acordo de algum jeito: repare em quem honra **e** rentabiliza.',
                'options' => [
                    ['Faço upgrade cortesia para suíte, liberando standard para venda a preço cheio.', 'Entrega suíte grátis com demanda a R$ 1.200. Perde +R$ 500 de receita potencial por UH.', -50],
                    ['Confirmo standard a R$ 580 sem oferta adicional. Suítes ficam a R$ 1.200.', 'Cumpre o contrato, mas deixa dinheiro na mesa — o cliente poderia aceitar o upsell.', 80],
                    ['Confirmo standard a R$ 580 e ofereço upsell para suíte a R$ 696 (20% sobre o acordo).', 'Honra o acordo, rentabiliza com upsell inteligente e preserva yield das suítes para demanda aberta.', 150],
                    ['Ofereço suíte por R$ 950 como "condição especial" sem confirmar a standard.', 'Não honra a tarifa acordo. O cliente percebe que estão tentando vender mais caro.', 0],
                ],
            ],
            [
                'key' => 'sp2026-ocupacao',
                'source' => 'roc-sp-2026',
                'label' => 'A OCUPAÇÃO ESTAGNOU',
                'title' => 'O que você faz para destravar?',
                'context' => 'Seu hotel tem diária média 35% acima do mercado e RevPAR 10% superior. Mas a ocupação trava em 67% há 4 meses (mercado opera a 78%). Sua análise: o pickup para 15 dias antes da data — os últimos 11% vão para concorrentes mais baratos. GG cobra crescimento.',
                'bias' => 'Defender a posição atual com dados corretos não é o mesmo que propor como crescer — o GG quer ação.',
                'options' => [
                    ['Faço estudo de elasticidade por segmento para entender onde posso flexibilizar tarifa sem impactar a diária média geral. Prazo de entrega: 30 dias.', 'Estudo válido, mas 30 dias para entregar quando o GG cobra agora. Perde relevância.', 0],
                    ['Junto com distribuição e marketing, crio oferta de last minute (7 dias antes) em 1-2 canais específicos, com tarifa acima do set mas com valor agregado incluso. Testo por 60 dias e meço impacto na diária média.', 'Ação cirúrgica no ponto exato onde perde demanda, sem contaminar a estratégia geral. Testa, mede e ajusta.', 150],
                    ['Apresento ao GG o comparativo mostrando que nosso RevPAR é 10% superior mesmo com menos ocupação, e proponho trocar a meta de ocupação por rentabilidade por UH.', 'Dados corretos, mas só defende a posição atual — não propõe como crescer. O GG quer ação.', 80],
                    ['Reduzo tarifa em 15% nos últimos 15 dias antes da data para capturar a demanda que está indo para os concorrentes mais baratos.', 'Redução linear destrói diária média sem segmentação. Canibalização.', -50],
                ],
            ],
        ];
    }

    /** O conjunto anterior, mantido desligado para poder ser reativado. */
    protected function scenariosOriginais(): array
    {
        return [
            [
                'key' => 'original-tarifa',
                'source' => 'roc-original',
                'active' => false,
                'label' => 'TARIFA & OCUPAÇÃO',
                'title' => 'Como você responde à queda no ritmo de reservas?',
                'context' => 'Terça-feira, faltam 9 dias para o feriado. Forecast: ocupação 68% (meta 85%). Dois concorrentes diretos já anunciaram promoções para o mesmo período, e o ritmo de reservas está 12% abaixo do mesmo feriado do ano passado.',
                'bias' => 'Quem tem a missão Diária Média tende à C, achando que protege a tarifa; quem tem a missão Participação de Mercado tende à A, buscando volume rápido.',
                'options' => [
                    ['Reduzir tarifa 10% em todos os canais', 'Aumenta o pickup em cerca de 15 quartos. O desconto também se aplica a reservas que ocorreriam mesmo sem ele.', 80],
                    ['Tarifa relâmpago só no canal direto (48h)', 'Aumenta o pickup em volume semelhante à opção A. A tarifa cheia é mantida nos demais canais de venda.', 150],
                    ['Manter tarifa + estadia mínima de 2 noites', 'Preserva a diária média nominal. Reduz a demanda elegível, já que parte do público busca apenas 1 noite no feriado.', -50],
                    ['Esperar 48h monitorando o pickup', 'Mantém tarifa e inventário intactos. Adia a resposta em um mercado onde os concorrentes já estão se movendo.', 0],
                ],
            ],
            [
                'key' => 'original-grupo',
                'source' => 'roc-original',
                'active' => false,
                'label' => 'GRUPO & CONGRESSO',
                'title' => 'Como você organiza a demanda do congresso?',
                'context' => 'Congresso de 3 dias confirmado na cidade — demanda potencial de ~40 quartos. O hotel já tem 12 quartos de grupo confirmados para a mesma data, 10% abaixo da meta de diária.',
                'bias' => 'Quem tem a missão Reservas Diretas tende à B (fechar OTA parece reforçar o direto); quem tem a missão Diária Média tende à C, a mais equilibrada.',
                'options' => [
                    ['Subir a tarifa geral imediatamente', 'Aumenta a diária média nas vendas ainda abertas. Não se aplica ao bloco de grupo já confirmado, que segue na tarifa negociada antes.', 80],
                    ['Fechar o canal OTA por 48h', 'Redireciona parte da demanda para o canal direto. Reduz o alcance total num momento de pico de buscas pela cidade.', -50],
                    ['Criar tarifa corporativa fixa para o congresso, com mínimo de diárias', 'Organiza a demanda do evento em um bloco previsível, separado do inventário individual e do grupo já confirmado.', 150],
                    ['Esperar a demanda se confirmar sozinha', 'Não altera a operação agora. Agências de congresso costumam fechar bloqueios com antecedência, antes da demanda aparecer sozinha.', 0],
                ],
            ],
            [
                'key' => 'original-marketing',
                'source' => 'roc-original',
                'active' => false,
                'label' => 'INVESTIMENTO EM MARKETING',
                'title' => 'Em qual campanha você investe o orçamento aprovado?',
                'context' => 'A performance de mídia paga do hotel está 15% abaixo da meta mensal, e a diretoria pediu um resultado visível em 48h. Orçamento aprovado para apenas 1 campanha.',
                'bias' => 'Quem tem a missão Reservas Diretas tende à B ou C, pela visibilidade; quem tem a missão Diária Média ou Ocupação tende à D, olhando custo por reserva.',
                'options' => [
                    ['Google Ads segmentado para o período', 'Captura quem já está pesquisando ativamente o destino. Não amplia a demanda para quem ainda não considerou viajar.', 80],
                    ['Busca de marca (branded search) reforçando o site oficial do hotel', 'Reforça a visibilidade para quem já pretende reservar direto. Não amplia a demanda para quem ainda não considerou o hotel — o problema atual é de alcance, não de marca.', -50],
                    ['Parceria com influenciadores de viagem regionais', 'Gera alta exposição de marca e associação positiva. O custo é fixo, independente de quantas reservas a campanha gerar de fato.', -50],
                    ['Campanha de e-mail/CRM para hóspedes anteriores', 'Atinge uma base menor, mas já convertida antes. Custo por contato mais baixo entre as opções, com ciclo de decisão mais curto.', 150],
                ],
            ],
            [
                'key' => 'original-negociacao',
                'source' => 'roc-original',
                'active' => false,
                'label' => 'NEGOCIAÇÃO DE GRUPOS',
                'title' => 'Como você responde ao pedido do grupo de 60 quartos?',
                'context' => 'Hotel de 220 apartamentos. Grupo de 60 quartos solicitado a R$ 520 (meta de diária média é R$ 650). Forecast individual (reservas em carteira) indica 71% de ocupação na data — restam 64 quartos livres, com ritmo de reservas forte nos últimos 10 dias e diária média projetada de R$ 670 para a demanda individual remanescente.',
                'bias' => 'Quem tem a missão Participação de Mercado ou Ocupação tende à A, fechando o grupo logo; quem tem a missão Diária Média tende à D, pensando em receita total.',
                'options' => [
                    ['Aceitar tarifa integral', 'Garante o fechamento imediato do grupo. Desloca demanda individual que, pelo ritmo de reservas, tende a pagar tarifa mais alta na mesma data.', -50],
                    ['Negar o pedido', 'Preserva a diária média de tabela. Depende do forecast individual se converter integralmente para preencher os 60 quartos.', 0],
                    ['Negociar R$ 580 + mínimo de 3 noites (grupo cai para 45 quartos)', 'Aumenta a receita por quarto ocupado do grupo. Reduz o volume garantido, com risco de o cliente não aceitar as novas condições.', 80],
                    ['Negociar R$ 520 + consumo mínimo de F&B obrigatório', 'Mantém o volume de 60 quartos. Compensa a tarifa reduzida com receita adicional de alimentos e bebidas por quarto ocupado.', 150],
                ],
            ],
            [
                'key' => 'original-distribuicao',
                'source' => 'roc-original',
                'active' => false,
                'label' => 'DISTRIBUIÇÃO & MÍDIA PAGA',
                'title' => 'O que você faz com o aumento de CPC proposto?',
                'context' => 'O canal direto responde hoje por 22% das reservas, abaixo da meta de 30% definida pela diretoria. O Google Hotel Ads oferece posição de destaque nos resultados de busca por 30 dias, mediante aumento do CPC (custo por clique) em 40%.',
                'bias' => 'Quem tem a missão Ocupação tende à A, pela visibilidade imediata; quem tem a missão Diária Média tende à D, protegendo a margem.',
                'options' => [
                    ['Aceitar o aumento de CPC integral', 'Aumenta a visibilidade nos resultados de busca de imediato. O CPC maior incide sobre todos os cliques, não só os que geram reserva.', -50],
                    ['Recusar o aumento', 'Mantém o custo de mídia estável. Se concorrentes aceitarem, a posição do hotel nos resultados pode cair mesmo sem mudança de comportamento.', 0],
                    ['Aceitar o aumento por só 10 dias, no período de menor demanda', 'Concentra o investimento extra só onde a visibilidade adicional é mais necessária. Fora desse período, o CPC volta ao patamar anterior.', 80],
                    ['Negociar CPC diferenciado, maior só em conversões rastreadas como incrementais', 'Direciona o custo extra só para o resultado que ele de fato gera. Exige configuração de rastreamento de conversão mais sofisticada.', 150],
                ],
            ],

        ];
    }

    /**
     * O que fecha a Fase 2 depois dos cinco cenários de mesa: a rodada final e
     * o desempate.
     *
     * A rodada final é o clímax e a única pergunta do evento sem alternativas —
     * todas as mesas recebem a mesma missão, a que funde as 4 missões
     * individuais da Fase 1 num objetivo só, e decidem livremente. Sem
     * alternativa não há régua a aplicar, então a pontuação é lançada mesa a
     * mesa pelo facilitador (`manual_scoring`).
     *
     * Ela vem **depois** dos cinco: chegar nela tendo acabado de rejogar em
     * mesa o que cada um jogou sozinho é o que dá peso à decisão.
     */
    protected function closingRounds(int $scenarios): array
    {
        // a rodada final fecha a Fase 2, logo depois do último cenário; os
        // desempates vêm atrás dela, fora da contagem
        $final = $scenarios + 1;

        return [
            [
                'phase' => 2, 'round' => $final, 'mode' => Question::MODE_CONSENSUS,
                'duration' => self::PHASE_TWO_DURATION,
                'manual_scoring' => true,
                'source' => 'roc-original',
                'label' => 'RODADA FINAL',
                'title' => 'A missão final da mesa',
                'context' => 'Maximizar o resultado total do hotel — pensando ao mesmo tempo em diária média, ocupação, reservas diretas e participação de mercado, como o Comitê Comercial completo.',
                'bias' => 'As quatro missões da Fase 1 se fundem em uma só. Observe quem defende a própria missão até o fim e quem cede primeiro — a pontuação desta rodada é sua, lançada mesa a mesa no painel.',
                'options' => [],
            ],

            // ---------------------------------------------------------------
            // Desempates do PDF "Sala de Decisões ROC SP 2026". Ativos por
            // padrão; os dois [PREENCHER] originais ficam desligados, ao lado,
            // para o facilitador poder trocar o par pelo painel.
            // ---------------------------------------------------------------
            [
                'phase' => 2, 'round' => $final + 1, 'mode' => Question::MODE_CONSENSUS,
                'duration' => self::PHASE_TWO_DURATION,
                'is_bonus' => true,
                'source' => 'roc-sp-2026',
                'label' => 'DESEMPATE D1',
                'title' => 'O Momento da Verdade',
                'context' => 'Reunião de budget. CFO quer +15% no RevPAR. VP Comercial quer +20% em grupos. VP Operações pede -8% em custos. CEO pergunta: "Como RM entrega as três?"',
                'options' => [
                    ['Preciso de uma semana para simular cenários e trazer proposta detalhada.', 'Na frente do CEO, pedir tempo é perder o momento.', 0],
                    ['Entrego 15% de RevPAR com mix tarifa/ocupação. Grupos com piso. Custos via forecast.', 'Tecnicamente correto, mas responde cada meta isoladamente. Não conecta.', 80],
                    ['Entrego se o comercial não aceitar grupos abaixo da BAR e se não cortarem minha equipe.', 'Joga responsabilidade nos outros e reforça silos.', -50],
                    ['RevPAR via mix de receita por hóspede. Grupos com piso e consumo mínimo de A&B. Eficiência via forecast. Preciso das áreas alinhadas.', 'Conecta as três metas numa lógica única e posiciona RM como orquestrador.', 150],
                ],
            ],
            [
                'phase' => 2, 'round' => $final + 2, 'mode' => Question::MODE_CONSENSUS,
                'duration' => self::PHASE_TWO_DURATION,
                'is_bonus' => true,
                'source' => 'roc-sp-2026',
                'label' => 'DESEMPATE D2',
                'title' => 'O Grupo que Preenche',
                'context' => 'Operadora oferece 40 UHs/noite na baixa (de 180 disponíveis), 6 meses, tarifa 45% abaixo da BAR. Sua ocupação histórica na baixa: 55%. Exigências: 10% abaixo da paridade + quebra de mínimo de noites nos feriados.',
                'options' => [
                    ['Aceito integralmente. 22% de base na baixa é essencial.', 'Entrega tudo: desconto + paridade + feriados. Vende barato e perde controle.', -50],
                    ['Aceito 40 UHs, sem quebra nos feriados, aceito 10% abaixo da paridade, tarifa a 35% abaixo da BAR. Release de 21 dias + revisão trimestral.', 'Cede onde pode (paridade), protege onde dói (feriados), melhora tarifa e garante flexibilidade.', 150],
                    ['Peço 5 dias para rodar números e simular impacto.', 'A operadora tem prazo. Enquanto analisa, fecha com concorrente.', 0],
                    ['Aceito volume menor (25 UHs), sem quebra, paridade mantida, ofereço benefícios operacionais.', 'Ideal na teoria, mas a operadora não aceita redução de 40 para 25 sem contrapartida. Travaria.', 80],
                ],
            ],

            // ---------------------------------------------------------------
            // O desempate fica na última rodada da Fase 2 — depois da rodada
            // final, e fora da contagem (`is_bonus`), então "próxima rodada"
            // nunca cai nele. Só o botão de desempate do painel o carrega.
            // ---------------------------------------------------------------
            [
                'phase' => 2, 'round' => $final + 3, 'mode' => Question::MODE_CONSENSUS,
                'duration' => self::PHASE_TWO_DURATION,
                'is_bonus' => true,
                'source' => 'roc-original',
                'active' => false,
                'label' => 'DESEMPATE (a preencher) 1',
                'title' => '[PREENCHER] Primeira pergunta de desempate',
                'context' => '[PREENCHER com a primeira pergunta bônus. Usada apenas se o empate sobreviver aos três primeiros critérios de vitória. 120 segundos, consenso da mesa.]',
                'options' => [
                    ['[PREENCHER] Alternativa A', '[PREENCHER] Efeito da decisão A.', -50],
                    ['[PREENCHER] Alternativa B', '[PREENCHER] Efeito da decisão B.', 0],
                    ['[PREENCHER] Alternativa C', '[PREENCHER] Efeito da decisão C.', 80],
                    ['[PREENCHER] Alternativa D', '[PREENCHER] Efeito da decisão D.', 150],
                ],
            ],
            [
                'phase' => 2, 'round' => $final + 4, 'mode' => Question::MODE_CONSENSUS,
                'duration' => self::PHASE_TWO_DURATION,
                'is_bonus' => true,
                'source' => 'roc-original',
                'active' => false,
                'label' => 'DESEMPATE (a preencher) 2',
                'title' => '[PREENCHER] Segunda pergunta de desempate',
                'context' => '[PREENCHER com a segunda pergunta bônus. Usada só se o empate sobreviver à primeira.]',
                'options' => [
                    ['[PREENCHER] Alternativa A', '[PREENCHER] Efeito da decisão A.', -50],
                    ['[PREENCHER] Alternativa B', '[PREENCHER] Efeito da decisão B.', 0],
                    ['[PREENCHER] Alternativa C', '[PREENCHER] Efeito da decisão C.', 80],
                    ['[PREENCHER] Alternativa D', '[PREENCHER] Efeito da decisão D.', 150],
                ],
            ],
        ];
    }
}
