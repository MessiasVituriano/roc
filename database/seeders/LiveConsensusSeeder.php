<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\Question;
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
     * Tempo da rodada final. Bem maior que os 20s da Fase 1 porque a mesa
     * precisa discutir a missão inteira antes de fechar uma posição. O
     * facilitador ainda sobrescreve pelo painel.
     */
    public const FINAL_ROUND_DURATION = 300;

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
            'round_duration' => 20,
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

        $event->update([
            'current_question_id' => $event->questions()->where('phase', 1)->where('round', 1)->value('id'),
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

    protected function rounds(): array
    {
        return [
            [
                'phase' => 1, 'round' => 1, 'mode' => Question::MODE_INDIVIDUAL, 'duration' => 20,
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
                'phase' => 1, 'round' => 2, 'mode' => Question::MODE_INDIVIDUAL, 'duration' => 20,
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
                'phase' => 1, 'round' => 3, 'mode' => Question::MODE_INDIVIDUAL, 'duration' => 20,
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
                'phase' => 1, 'round' => 4, 'mode' => Question::MODE_INDIVIDUAL, 'duration' => 20,
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
                'phase' => 1, 'round' => 5, 'mode' => Question::MODE_INDIVIDUAL, 'duration' => 20,
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

            // ---------------------------------------------------------------
            // Fase 2 — a rodada final, e só ela.
            //
            // Diferente das rodadas 1 a 5, não é uma escolha entre alternativas
            // fixas: todas as mesas recebem a mesma missão final — a que funde
            // as 4 missões individuais da Fase 1 num objetivo só — e decidem
            // livremente por consenso. Sem alternativas não há régua a aplicar,
            // então a pontuação é lançada mesa a mesa pelo facilitador
            // (`manual_scoring`), no painel.
            // ---------------------------------------------------------------
            [
                'phase' => 2, 'round' => 1, 'mode' => Question::MODE_CONSENSUS,
                'duration' => self::FINAL_ROUND_DURATION,
                'manual_scoring' => true,
                'label' => 'RODADA FINAL',
                'title' => 'A missão final da mesa',
                'context' => 'Maximizar o resultado total do hotel — pensando ao mesmo tempo em diária média, ocupação, reservas diretas e participação de mercado, como o Comitê Comercial completo.',
                'bias' => 'As quatro missões da Fase 1 se fundem em uma só. Observe quem defende a própria missão até o fim e quem cede primeiro — a pontuação desta rodada é sua, lançada mesa a mesa no painel.',
                'options' => [],
            ],

            // ---------------------------------------------------------------
            // O desempate fica na rodada 2 da Fase 2 — depois da rodada final,
            // e fora da contagem (`is_bonus`), então "próxima rodada" nunca cai
            // nele. Só o botão de desempate do painel o carrega.
            // ---------------------------------------------------------------
            [
                'phase' => 2, 'round' => 2, 'mode' => Question::MODE_CONSENSUS, 'duration' => 60,
                'is_bonus' => true,
                'label' => 'DESEMPATE',
                'title' => '[PREENCHER] Pergunta bônus de desempate',
                'context' => '[PREENCHER com a pergunta bônus. Usada apenas se o empate sobreviver aos três primeiros critérios de vitória. 60 segundos, consenso da mesa.]',
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
