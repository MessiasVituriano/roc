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

        $event->update([
            'current_question_id' => $event->questions()
                ->where('phase', 1)->where('active', true)->orderBy('round')->value('id'),
        ]);
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
                'title' => 'O que você faz com a sugestão do RMS?',
                'context' => 'Seu RMS (Revenue Management System) sugere aumento de 18% na tarifa para o próximo fim de semana baseado em alta demanda detectada. Seu hotel está com 78% de ocupação confirmada. Porém, você sabe que há um grande evento corporativo na cidade e 60% do seu pickup atual é desse perfil. O evento foi cancelado há 2 horas. O RMS ainda não processou os cancelamentos que virão.',
                'bias' => 'A diferença entre +150 e +80 está no timing: os dois ignoram o RMS, mas um confirma o estrago antes de queimar tarifa.',
                'options' => [
                    ['Ignoro o aumento, mantenho a tarifa atual e monitoro o pickup nas próximas horas. Se os cancelamentos se confirmarem, entro com promoção de last minute em 1 ou 2 canais de retorno imediato para recompor.', 'Corrige a máquina com inteligência humana, não entra em pânico, e tem plano B cirúrgico pronto para agir rápido.', 150],
                    ['Sigo a sugestão do RMS. O sistema tem mais dados do que eu e historicamente acerta na maioria das vezes.', 'Confia na máquina sem considerar informação que ela ainda não tem. Vai subir preço na véspera de cancelamentos.', 0],
                    ['Aplico o aumento de 18% agora e, quando os cancelamentos vierem, reduzo agressivamente para preencher.', 'Sobe e desce tarifa em 48h. Confunde o mercado, perde credibilidade de preço e sai perdendo nos dois movimentos.', -50],
                    ['Ignoro o aumento e já abro imediatamente promoções em todos os canais para antecipar a queda de ocupação que virá.', 'Reage rápido, mas abre promoção antes de confirmar o tamanho do estrago. Pode estar queimando tarifa sem necessidade.', 80],
                ],
            ],
            [
                'key' => 'sp2026-concorrente',
                'source' => 'roc-sp-2026',
                'label' => 'O CONCORRENTE DESESPERADO',
                'title' => 'O que você faz?',
                'context' => 'Seu hotel 5 estrelas opera com 72% de ocupação e diária média de R$ 890. O principal concorrente direto acaba de derrubar a tarifa em 35% numa flash sale agressiva para o próximo feriado prolongado. Sua equipe comercial está pressionando para reagir. O feriado é daqui a 18 dias. Seu pickup está estável.',
                'bias' => 'Os 18 dias são a régua: o que separa +150 de +80 é o que dá para maturar nesse prazo.',
                'options' => [
                    ['Mantenho a tarifa e monitoro o pickup por 7 dias. Se não evoluir, crio um pacote sem reduzir a BAR (Best Available Rate).', 'Perde 7 dos 18 dias sem agir. Quando decidir, a janela já encolheu e o concorrente já captou a demanda.', 0],
                    ['Reduzo a tarifa em 30% para ficar competitivo. Não posso perder esse feriado.', 'Entra na espiral de commoditização, destrói diária média e posicionamento de marca por um único feriado.', -50],
                    ['Mantenho a tarifa, trabalho upsell com a base de clientes, ofereço MAP (Meia Pensão) e serviços adicionais, e fecho canais de menor rentabilidade para manter pickup estável e rentabilizar a diária média.', 'Ação imediata, viável em 18 dias: rentabiliza sem reduzir preço, usa canais e base própria, protege posicionamento.', 150],
                    ['Mantenho a tarifa e aciono o marketing para reforçar diferenciais de experiência com campanhas de valor agregado para converter indecisos.', 'Direção correta, mas campanhas de marketing precisam de tempo para maturar — 18 dias é curto para essa estratégia gerar resultado.', 80],
                ],
            ],
            [
                'key' => 'sp2026-contrato',
                'source' => 'roc-sp-2026',
                'label' => 'O CONTRATO CORPORATIVO QUE VENCEU',
                'title' => 'Como você conduz a renovação?',
                'context' => 'O maior contrato corporativo do seu hotel (18% da ocupação total, 420 room nights/mês) está em renovação. A empresa pede redução de 12% na tarifa negociada alegando que "o mercado está mais competitivo". Sua análise mostra que o volume real caiu 30% nos últimos 6 meses (de 420 para 290 RN/mês). A tarifa atual já está 25% abaixo da BAR (Best Available Rate). O comercial quer renovar "para não perder o cliente".',
                'bias' => 'O dado do volume real é a chave: quem não o usa cede preço sobre quartos que o cliente já não compra.',
                'options' => [
                    ['Aceito redução de 5% (meio-termo) mantendo o volume contratado de 420 RN com cláusula de penalidade se não atingir 80% do compromisso.', 'Parece equilibrado, mas cede preço sobre um volume que o cliente já não entrega. Na prática, vai pagar menos por menos quartos.', 80],
                    ['Renovo com a tarifa atual (sem redução), mas ajusto o volume contratado para o real (290 RN). Proponho cláusula de performance: se voltarem a 420 RN, ganham 5% de desconto progressivo. Incluo benefícios de valor (upgrade sob disponibilidade, late checkout).', 'Usa dados reais para negociar, não cede preço, mas oferece caminho para o cliente ganhar se entregar volume. Realista e justo.', 150],
                    ['Aceito os 12% de redução para garantir a renovação. Perder 18% da ocupação seria catastrófico.', 'Cede preço sobre volume que já caiu. Na prática vai receber menos dinheiro por menos quartos. Dupla perda.', -50],
                    ['Peço 15 dias para analisar o histórico completo e apresentar uma contraproposta embasada.', 'O cliente tem outras propostas na mesa. 15 dias é tempo demais — pode fechar com concorrente.', 0],
                ],
            ],
            [
                'key' => 'sp2026-upsell',
                'source' => 'roc-sp-2026',
                'label' => 'O DILEMA DO UPSELL',
                'title' => 'Como você resolve?',
                'context' => 'Seu hotel urbano de 200 UHs (Unidades Habitacionais) está com 94% de ocupação para amanhã. Restam 12 apartamentos: 4 standards e 8 suítes. Um cliente fidelizado (15 estadias/ano, diária média histórica de R$ 650) liga pedindo uma standard por R$ 580 (tarifa corporativa negociada). Simultaneamente, há demanda orgânica para suítes a R$ 1.200 no site.',
                'bias' => 'Todas honram ou quebram a tarifa acordo de algum jeito: repare em quem honra **e** rentabiliza.',
                'options' => [
                    ['Faço upgrade cortesia para suíte para fidelizar, liberando a standard para venda a preço cheio.', 'Entrega suíte grátis quando há demanda a R$ 1.200. Perde mais de R$ 500 de receita potencial por UH. Generosidade que custa caro.', -50],
                    ['Confirmo a standard a R$ 580 sem oferta adicional. Mantenho as 8 suítes a R$ 1.200 para demanda orgânica.', 'Cumpre o contrato e protege suítes, mas deixa dinheiro na mesa — o cliente poderia aceitar o upsell e todos ganhariam.', 80],
                    ['Confirmo a standard a R$ 580 e ofereço upsell para suíte com 20% sobre a tarifa acordo (R$ 696), posicionando como condição exclusiva de fidelidade. Suítes restantes seguem a R$ 1.200 no site.', 'Honra o acordo, rentabiliza com upsell inteligente, faz o cliente se sentir valorizado e preserva yield das suítes para demanda aberta.', 150],
                    ['Ofereço a suíte diretamente por R$ 950 como "upgrade com desconto especial" sem confirmar a standard primeiro.', 'Parece generoso, mas não honra a tarifa acordo. O cliente percebe que estão tentando vender mais caro do que o combinado.', 0],
                ],
            ],
            [
                'key' => 'sp2026-budget',
                'source' => 'roc-sp-2026',
                'label' => 'O MOMENTO DA VERDADE',
                'title' => 'Qual é a sua resposta?',
                'context' => 'Reunião de budget do próximo ano. O CFO (Diretor Financeiro) quer +15% no RevPAR (Receita por Apartamento Disponível). O VP Comercial quer +20% em volume de grupos. O VP de Operações pede contenção de custos de 8%. O CEO pergunta diretamente para você: "Como o Revenue Management vai entregar resultado com essas três demandas simultâneas?" Todos olham para você.',
                'bias' => 'A rodada fecha a tese: o que separa +150 de +80 é conectar as três metas numa lógica só, em vez de responder uma a uma.',
                'options' => [
                    ['Preciso de uma semana para simular cenários e voltar com uma proposta detalhada que contemple as três metas.', 'Na frente do CEO, com todos olhando, pedir tempo é perder o momento.', 0],
                    ['RevPAR cresce via mix — mais receita por hóspede com upsell, A&B (Alimentos e Bebidas) e serviços, não só mais quartos. Grupos entram com piso tarifário e consumo mínimo de A&B para não canibalizar. Eficiência vem do forecast: quanto melhor a previsão, menos desperdício operacional. Mas preciso que cada área se comprometa com a sua parte.', 'Conecta as três metas numa lógica única, mostra como cada área contribui e posiciona RM como orquestrador.', 150],
                    ['Posso entregar 15% de RevPAR com equilíbrio entre tarifa e ocupação. Para grupos, preciso de piso tarifário definido. Para custos, melhoro a acurácia do forecast para a operação dimensionar melhor.', 'Tecnicamente correto, mas responde cada meta isoladamente. Não mostra como as três se conectam.', 80],
                    ['Posso entregar os 15% de RevPAR, mas preciso que o comercial pare de aceitar grupos abaixo da BAR e que a operação não corte minha equipe de RM.', 'Joga responsabilidade nos outros, cria conflito na reunião e reforça silos.', -50],
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
                'title' => 'A Ocupação Estagnou',
                'context' => 'Seu hotel tem diária média 35% acima do set competitivo e RevPAR (Receita por Apartamento Disponível) 10% superior. Porém, a ocupação está estagnada em 67% há 4 meses enquanto o mercado opera a 78%. O GG (Gerente Geral) cobra crescimento. Sua análise mostra que o pickup para de evoluir consistentemente 15 dias antes da data — os últimos 11% de ocupação vão para concorrentes mais baratos.',
                'options' => [
                    ['Apresento os dados ao GG mostrando que nosso RevPAR é superior e proponho meta de rentabilidade por UH ao invés de ocupação pura.', 'Dados corretos, mas só defende a posição atual — não propõe como crescer. O GG quer ação, não justificativa.', 80],
                    ['Reduzo a tarifa em 15% nos últimos 15 dias antes da data para capturar a demanda que está indo para concorrentes.', 'Redução linear destrói a diária média sem segmentação. Hóspedes que já reservaram verão a queda. Canibalização.', -50],
                    ['Trabalho com distribuição e marketing para criar uma oferta de last minute (7 dias antes) em 1 ou 2 canais específicos, com tarifa ainda acima do set mas com valor agregado (late checkout, A&B incluso). Testo por 60 dias e meço o impacto na diária média total.', 'Ação cirúrgica no ponto exato onde perde demanda, sem contaminar a estratégia geral. Testa, mede e ajusta.', 150],
                    ['Analiso a elasticidade por segmento para entender onde posso flexibilizar tarifa sem impactar a diária média geral. Apresento estudo em 30 dias.', 'Estudo válido, mas 30 dias para entregar análise quando o GG cobra agora. Perde relevância e protagonismo.', 0],
                ],
            ],
            [
                'phase' => 2, 'round' => $final + 2, 'mode' => Question::MODE_CONSENSUS,
                'duration' => self::PHASE_TWO_DURATION,
                'is_bonus' => true,
                'source' => 'roc-sp-2026',
                'label' => 'DESEMPATE D2',
                'title' => 'O Grupo que Preenche, Mas...',
                'context' => 'Uma operadora oferece um contrato de 40 UHs (Unidades Habitacionais) por noite (de 180 disponíveis) durante a baixa temporada, por 6 meses, a uma tarifa 45% abaixo da sua BAR (Best Available Rate). Isso garantiria 22% de ocupação base. Historicamente, sua ocupação na baixa é de 55%. A operadora exige tarifa 10% abaixo da paridade e quebra do mínimo de noites nos feriados prolongados.',
                'options' => [
                    ['Aceito integralmente. 22% de base garantida na baixa é um colchão que reduz minha ansiedade de pickup.', 'Entrega 45% de desconto + quebra de paridade + feriados. Vende o hotel barato e perde controle.', -50],
                    ['Aceito as 40 UHs, mas renegocio: sem quebra de mínimo nos feriados, aceito os 10% abaixo da paridade, com tarifa a 35% abaixo da BAR. Contrapartida: release de 21 dias e cláusula de revisão trimestral.', 'Negociação realista: cede onde pode (paridade), protege onde dói (feriados), melhora tarifa e garante flexibilidade.', 150],
                    ['Peço 5 dias para rodar os números e simular o impacto no RevPAR antes de responder.', 'Parece prudente, mas a operadora tem prazo. Enquanto analisa, ela fecha com o concorrente.', 0],
                    ['Aceito um volume menor (25 UHs), sem quebra de mínimo nos feriados, com paridade mantida, e ofereço benefícios operacionais ao invés de desconto adicional.', 'Ideal na teoria, mas na prática a operadora não aceita redução de 40 para 25 UHs sem contrapartida. Negociação travaria.', 80],
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
