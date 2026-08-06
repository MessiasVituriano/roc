<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventTable;
use App\Models\Participant;
use App\Models\ParticipantVote;
use App\Models\Question;
use App\Models\TableVote;
use Illuminate\Support\Collection;

/**
 * Monta os payloads lidos por todas as telas.
 *
 * Os formatos aqui são o contrato público da API. Polling é apenas o
 * transporte atual — publicar estes mesmos payloads por websocket depois não
 * exige mudança alguma no frontend.
 *
 * Duas regras de sigilo, em camadas:
 *
 * 1. **Nada de resultado antes do clique do facilitador.** Durante a votação o
 *    telão vê só quantos votos chegaram, nunca a distribuição.
 * 2. **Nada de gabarito antes do fecho.** A revelação de cada rodada mostra a
 *    *divergência* — a distribuição dos votos — e nada mais. Pontuação é
 *    gabarito com outro nome: numa mesa pequena, o total depois da rodada 1
 *    identifica a alternativa certa por aritmética, e saber a régua da rodada 1
 *    muda como a sala joga as rodadas seguintes. Melhor alternativa, pontos e
 *    efeitos só saem do servidor no clique do fecho.
 */
class EventStateService
{
    public function __construct(protected ScoreService $scores) {}

    public function activeEvent(): ?Event
    {
        return Event::query()->orderByDesc('id')->first();
    }

    /**
     * Se a régua (melhor alternativa, pontos, efeitos, acertos) pode ir para a
     * tela.
     *
     * Três portas: o painel do facilitador, que precisa dela para conduzir e
     * nunca é projetado; o clique deliberado que abre o gabarito e o
     * comparativo com a sala ainda inteira; e o encerramento, que abre de
     * qualquer jeito.
     */
    public function showsAnswerKey(Event $event, bool $forMaster = false): bool
    {
        return $forMaster
            || $event->answers_revealed
            || $event->status === Event::STATUS_FINISHED;
    }

    /**
     * Payload leve, consultado a cada segundo pela tela do participante.
     */
    public function status(Event $event, ?Participant $participant = null): array
    {
        $question = $event->currentQuestion();
        $revealed = $event->round_status === Event::ROUND_REVEALED;
        $key = $this->showsAnswerKey($event);

        $payload = [
            'event' => $this->event($event),
            'timer' => $this->timer($event),
            'question' => $question ? $this->question($question, withAnswerKey: $key) : null,
            'progress' => $this->progress($event, $question),
            'results' => $revealed && $question ? $this->results($question, withAnswerKey: $key) : null,
        ];

        if ($participant) {
            $table = $participant->table;
            $mission = $participant->mission;

            $myVote = $question
                ? ParticipantVote::where('participant_id', $participant->id)
                    ->where('question_id', $question->id)
                    ->first()
                : null;

            $tableVote = $question
                ? TableVote::where('event_table_id', $table->id)
                    ->where('question_id', $question->id)
                    ->first()
                : null;

            $mine = $event->isIndividualPhase() ? $myVote : $tableVote;

            $payload['me'] = [
                'id' => $participant->id,
                'name' => $participant->name,
                'gender' => $participant->gender,
                'avatar_seed' => $participant->avatar_seed,
                'is_representative' => $table->representative_id === $participant->id,
                'can_answer' => $this->canAnswer($event, $participant, $table, $question),
                'has_voted' => $mine !== null,
                'voted_option_id' => $mine?->option_id,
                // Os pontos da própria escolha entregam o gabarito tão bem
                // quanto o selo de "melhor decisão", e o acerto entrega direto:
                // os dois só saem no fecho do evento.
                //
                // O **total** é a exceção, e por isso tem porta própria. Ele sai
                // no fecho da Fase 1, quando as cinco rodadas já acabaram: 450
                // pontos não identificam a melhor alternativa de nenhuma delas,
                // enquanto o valor de uma rodada isolada identificaria.
                'round_points' => $key ? $mine?->points : null,
                'total_points' => $key || $event->phase_one_revealed
                    ? $this->scores->participantPoints($event, $participant)
                    : null,
                'correct' => $key ? $this->scores->participantCorrect($event, $participant) : null,
                'rounds' => $event->roundsInPhase(1),
                'mission' => $mission ? [
                    'id' => $mission->id,
                    'key' => $mission->key,
                    'name' => $mission->name,
                    'statement' => $mission->statement,
                    'icon' => $mission->icon,
                    'color' => $mission->color,
                ] : null,
            ];

            $payload['my_table'] = [
                'id' => $table->id,
                'name' => $table->name,
                'icon' => $table->icon,
                'color' => $table->color,
                'representative_id' => $table->representative_id,
                'representative_name' => $table->representative?->name,
                'has_voted' => $tableVote !== null,
                'participants' => $table->participants()
                    ->orderBy('id')
                    ->get()
                    ->map(fn (Participant $p) => $this->participant($p))
                    ->all(),
            ];
        }

        return $payload;
    }

    /**
     * Payload completo do telão e do painel master: o mesmo estado mais o mapa
     * do auditório e os placares.
     */
    public function display(Event $event, bool $forMaster = false): array
    {
        $question = $event->currentQuestion();
        $revealed = $event->round_status === Event::ROUND_REVEALED;
        $key = $this->showsAnswerKey($event, $forMaster);

        $voters = $question ? $this->voterIds($question) : collect();
        $answeredTables = $question ? $this->answeredTableIds($question) : collect();

        $tables = $event->tables()
            ->with(['participants' => fn ($q) => $q->orderBy('id')])
            ->orderBy('id')
            ->get()
            ->map(function (EventTable $table) use ($event, $voters, $answeredTables, $forMaster) {
                $participants = $table->participants;
                $online = $participants->filter(fn (Participant $p) => $p->isOnline())->count();

                $voted = $event->isIndividualPhase()
                    ? $participants->filter(fn (Participant $p) => $voters->contains($p->id))->count()
                    : ($answeredTables->contains($table->id) ? 1 : 0);

                $expected = $event->isIndividualPhase() ? $participants->count() : 1;
                $done = $expected > 0 && $voted >= $expected;

                return [
                    'id' => $table->id,
                    'name' => $table->name,
                    'icon' => $table->icon,
                    'color' => $table->color,
                    'position_x' => $table->position_x,
                    'position_y' => $table->position_y,
                    'representative_id' => $table->representative_id,
                    'state' => $this->tableState($event, $participants, $online, $done),
                    'answered' => $voted,
                    'expected' => $expected,
                    'percent' => $expected > 0 ? (int) round($voted / $expected * 100) : 0,
                    'has_voted' => $done,
                    'participants_count' => $participants->count(),
                    'online_count' => $online,
                    'participants' => $participants
                        ->map(fn (Participant $p) => $this->participant($p) + [
                            'voted' => $voters->contains($p->id),
                        ] + ($forMaster ? [
                            // decisão de moderação do facilitador: existe no
                            // painel dele e em lugar nenhum mais
                            'blocked' => $p->isBlocked(),
                            // o hotel identifica quem é quem numa sala de 150 —
                            // dois "Ana S." só se distinguem por ele
                            'hotel' => $p->hotel,
                        ] : []))
                        ->all(),
                ];
            })
            ->all();

        $payload = [
            'event' => $this->event($event),
            'timer' => $this->timer($event),
            'question' => $question ? $this->question($question, withAnswerKey: $key, forMaster: $forMaster) : null,
            'progress' => $this->progress($event, $question),
            'results' => $revealed && $question ? $this->results($question, withAnswerKey: $key) : null,
            'tables' => $tables,
            'stats' => [
                'participants' => $event->participants()->count(),
                'connected' => $event->participants()
                    ->where('last_seen', '>', now()->subSeconds(config('live.presence_ttl')))
                    ->count(),
                'tables' => count($tables),
            ],
        ];

        // Camada 3 — o placar do critério de vitória. Pontuação é gabarito
        // disfarçado: numa mesa pequena, `phase_one_points` depois da rodada 1
        // identifica a alternativa certa por aritmética. Vai para o telão só no
        // encerramento; o facilitador tem sempre.
        //
        // O ranking é calculado sempre, mas só **publicado** quando o gabarito
        // abre: o telão precisa saber do empate na liderança antes disso (é o
        // que explica a rodada a mais), e para isso basta o fato, não os
        // números.
        $ranking = $this->scores->tableRanking($event);

        if ($this->showsAnswerKey($event, $forMaster)) {
            $payload['table_ranking'] = $ranking;
            $payload['phase_comparison'] = $this->scores->phaseComparison($event);
        }

        // A rodada final: o facilitador precisa da lista de mesas o tempo todo
        // (é onde ele lança os pontos); o telão só depois do clique que revela
        // a rodada, ou no fecho.
        $final = $event->finalQuestion();

        if ($final && ($forMaster || ($revealed && $question?->id === $final->id) || $this->showsAnswerKey($event))) {
            $payload['final_round'] = [
                'question_id' => $final->id,
                'label' => $final->label,
                'title' => $final->title,
                'context' => $final->context,
                'scores' => $this->scores->finalRoundScores($event, $final),
            ];
        }

        // camada 2 — o viés por missão só vai ao telão na virada de fase, e os
        // acertos só junto com o gabarito (a virada acontece antes da Fase 2)
        if ($event->missions_revealed || $forMaster) {
            $payload['mission_ranking'] = $this->scores->missionRanking($event, withAnswerKey: $key);
        }

        // o gabarito, enfim: o telão pode mostrar o que a sala passou o evento
        // inteiro sem saber. Fica fora do payload do master, que já tem
        // /admin/questions e é consultado a cada segundo.
        if (! $forMaster && $this->showsAnswerKey($event)) {
            $payload['answer_key'] = $this->answerKey($event);
        }

        // Camada 1 — ranking individual. O facilitador tem sempre, para conduzir;
        // o telão só depois do clique que libera o gabarito, porque pontuação
        // individual é gabarito com outro nome. É o que premia quem decidiu
        // melhor sozinho, na tela de encerramento.
        if ($this->showsAnswerKey($event, $forMaster)) {
            // O painel recebe a lista inteira porque reordena por individual,
            // mesa ou total no cliente — truncar aqui esconderia justamente
            // quem tem individual baixo e mesa alta. O telão só premia o topo.
            // Os bloqueados e o hotel de cada pessoa só existem no painel.
            $payload['individual_ranking'] = $this->scores->individualRanking(
                $event,
                limit: $forMaster ? 0 : 10,
                forMaster: $forMaster,
            );
        }

        // O empate na liderança vai também ao telão — mas só o **fato**: quais
        // mesas ainda estão empatadas, sem pontuação nenhuma. É o que faz a
        // sala entender por que existe uma rodada a mais; mostrar os números
        // junto entregaria o placar antes do fecho.
        $tied = $this->scores->needsTieBreak($ranking);

        $payload['needs_tie_break'] = $tied;
        $payload['tied_tables'] = $tied
            ? collect($ranking)
                ->filter(fn (array $row) => $row['tied_with_leader'] || $row['position'] === 1)
                ->map(fn (array $row) => [
                    'table_id' => $row['table_id'],
                    'name' => $row['name'],
                    'icon' => $row['icon'],
                    'color' => $row['color'],
                ])
                ->values()
                ->all()
            : [];

        if ($forMaster) {
            // as mesas ainda empatadas, com as pessoas de cada uma: é a última
            // alternativa do critério de vitória, e ela é do facilitador
            $payload['tie_break'] = $this->scores->tieBreakDetail($event, $ranking);
        }

        return $payload;
    }

    public function event(Event $event): array
    {
        return [
            'id' => $event->id,
            'title' => $event->title,
            'status' => $event->status,
            'phase' => $event->phase,
            'round' => $event->current_round,
            'total_rounds' => $event->roundsInPhase(),
            'round_status' => $event->round_status,
            'phase_mode' => $event->isIndividualPhase()
                ? Question::MODE_INDIVIDUAL
                : Question::MODE_CONSENSUS,
            'last_phase' => Event::LAST_PHASE,
            'missions_revealed' => $event->missions_revealed,
            'phase_one_revealed' => $event->phase_one_revealed,
            'answers_revealed' => $event->answers_revealed,
            'voting_open' => $event->isAcceptingVotes(),
        ];
    }

    public function timer(Event $event): array
    {
        return [
            'remaining' => $event->remainingSeconds(),
            'duration' => $event->round_duration,
            'ends_at' => $event->round_ends_at?->toIso8601String(),
            'server_time' => now()->toIso8601String(),
        ];
    }

    /**
     * Quantos votos já chegaram. Durante a votação é só isso que o telão
     * mostra — a distribuição fica escondida até a revelação.
     */
    public function progress(Event $event, ?Question $question): array
    {
        if (! $question) {
            return ['answered' => 0, 'total' => 0, 'percent' => 0, 'unit' => 'participants'];
        }

        if ($question->isIndividual()) {
            $answered = ParticipantVote::where('question_id', $question->id)->count();
            $total = $event->participants()->count();
            $unit = 'participants';
        } else {
            $answered = TableVote::where('question_id', $question->id)->count();
            $total = $event->tables()->count();
            $unit = 'tables';
        }

        return [
            'answered' => $answered,
            'total' => $total,
            'percent' => $total > 0 ? (int) round($answered / $total * 100) : 0,
            'unit' => $unit,
        ];
    }

    /**
     * Distribuição da rodada. Só é chamado depois que o facilitador revelou.
     *
     * Sem `$withAnswerKey` sai apenas quem votou em quê — a divergência da
     * sala, que é o que a Fase 1 precisa mostrar. Nem os pontos, nem os
     * efeitos, nem a ordenação por régua: colocar a melhor alternativa no topo
     * entrega a resposta com a mesma eficiência de um selo dizendo isso.
     */
    public function results(Question $question, bool $withAnswerKey = false): array
    {
        if ($question->isManual()) {
            return $this->manualResults($question);
        }

        $counts = $question->isIndividual()
            ? ParticipantVote::where('question_id', $question->id)
                ->selectRaw('option_id, count(*) as total')
                ->groupBy('option_id')
                ->pluck('total', 'option_id')
            : TableVote::where('question_id', $question->id)
                ->selectRaw('option_id, count(*) as total')
                ->groupBy('option_id')
                ->pluck('total', 'option_id');

        $total = (int) $counts->sum();
        $best = $withAnswerKey ? $question->bestOption() : null;

        $rows = $question->options->map(fn ($option) => array_filter([
            'option_id' => $option->id,
            'text' => $option->text,
            'votes' => (int) ($counts[$option->id] ?? 0),
            'percent' => $total > 0 ? (int) round(($counts[$option->id] ?? 0) / $total * 100) : 0,
            'effect' => $withAnswerKey ? $option->effect : null,
            'points' => $withAnswerKey ? $option->points : null,
            // a cor é derivada dos pontos (verde = melhor, vermelho = pior):
            // mandá-la sem gabarito seria mandar o gabarito pintado
            'color' => $withAnswerKey ? $option->color : null,
            'is_best' => $withAnswerKey ? ($best !== null && $option->id === $best->id) : null,
        ], fn ($value) => $value !== null));

        // com gabarito, a ordem que importa é a da régua — a plateia precisa
        // ver qual era a melhor decisão. Sem ele, a ordem original da pergunta,
        // a mesma em que as pessoas votaram.
        $rows = $withAnswerKey ? $rows->sortByDesc('points') : $rows;

        return [
            'mode' => $question->mode,
            'manual' => false,
            'unit' => $question->isIndividual() ? 'participants' : 'tables',
            'total_votes' => $total,
            'has_answer_key' => $withAnswerKey,
            'options' => $rows->values()->all(),
        ];
    }

    /**
     * A revelação da rodada final. Não há alternativas para distribuir: o que
     * vai ao telão é a pontuação que o facilitador lançou para cada mesa,
     * ordenada — aqui a leitura é o ranking, não a divergência.
     */
    protected function manualResults(Question $question): array
    {
        $scores = collect($this->scores->finalRoundScores($question->event, $question));

        return [
            'mode' => $question->mode,
            'manual' => true,
            'unit' => 'tables',
            'total_votes' => $scores->where('scored', true)->count(),
            // sem régua não há gabarito: a pontuação já é o resultado
            'has_answer_key' => false,
            'options' => [],
            'tables' => $scores
                ->sortByDesc(fn (array $row) => $row['points'] ?? PHP_INT_MIN)
                ->values()
                ->all(),
        ];
    }

    /**
     * O gabarito, liberado só no fecho.
     *
     * Cobre os cinco cenários com régua. Como a Fase 2 rejoga os mesmos cinco,
     * cada linha carrega o **índice de respostas dos dois lados**: quanto da
     * sala chegou sozinha à melhor decisão e quantas mesas chegaram juntas. É a
     * mesma pergunta e o mesmo gabarito, então os dois números se comparam
     * diretamente — é isso que o fecho mostra.
     *
     * A rodada final fica de fora: é uma missão aberta, pontuada à mão, e
     * aparece no payload por outro caminho (`final_round`).
     *
     * @return array<int, array<string, mixed>>
     */
    public function answerKey(Event $event): array
    {
        $questions = $event->questions()
            ->with('options')
            ->where('phase', 1)
            ->where('is_bonus', false)
            ->where('active', true)
            ->orderBy('round')
            ->get();

        // o gêmeo de mesa de cada cenário, pela rodada
        $mirrored = $event->questions()
            ->with('options')
            ->where('phase', 2)
            ->where('is_bonus', false)
            ->where('manual_scoring', false)
            ->where('active', true)
            ->get()
            ->keyBy('round');

        $byPerson = ParticipantVote::whereIn('question_id', $questions->pluck('id'))
            ->selectRaw('question_id, option_id, count(*) as total')
            ->groupBy('question_id', 'option_id')
            ->get()
            ->mapWithKeys(fn ($row) => ["{$row->question_id}:{$row->option_id}" => (int) $row->total]);

        $byTable = TableVote::whereIn('question_id', $mirrored->pluck('id'))
            ->selectRaw('question_id, option_id, count(*) as total')
            ->groupBy('question_id', 'option_id')
            ->get()
            ->mapWithKeys(fn ($row) => ["{$row->question_id}:{$row->option_id}" => (int) $row->total]);

        return $questions
            ->map(function (Question $question) use ($byPerson, $byTable, $mirrored) {
                $best = $question->bestOption();

                $options = $question->options->map(fn ($option) => [
                    'text' => $option->text,
                    'effect' => $option->effect,
                    'points' => $option->points,
                    'color' => $option->color,
                    'is_best' => $best !== null && $option->id === $best->id,
                    'individual_votes' => $byPerson["{$question->id}:{$option->id}"] ?? 0,
                ]);

                $people = $options->sum('individual_votes');
                $winner = $options->firstWhere('is_best', true);

                return [
                    'round' => $question->round,
                    'label' => $question->label,
                    'title' => $question->title,
                    'best_text' => $winner['text'] ?? null,
                    'best_effect' => $winner['effect'] ?? null,
                    'best_points' => $winner['points'] ?? null,
                    // quanta gente escolheu sozinha a melhor decisão
                    'individual_accuracy' => $people > 0
                        ? (int) round(($winner['individual_votes'] ?? 0) / $people * 100)
                        : null,
                    // e quantas mesas chegaram nela conversando
                    'table_accuracy' => $this->bestShare($mirrored->get($question->round), $byTable),
                    'options' => $options->sortByDesc('points')->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Quanto por cento das decisões de uma pergunta caiu na melhor alternativa.
     *
     * @param  Collection<string, int>  $votes  contagem por "question:option"
     */
    protected function bestShare(?Question $question, Collection $votes): ?int
    {
        if (! $question) {
            return null;
        }

        $best = $question->bestOption();
        $total = $question->options->sum(fn ($o) => $votes["{$question->id}:{$o->id}"] ?? 0);

        if ($total === 0 || ! $best) {
            return null;
        }

        return (int) round(($votes["{$question->id}:{$best->id}"] ?? 0) / $total * 100);
    }

    /**
     * A pergunta como ela pode ser vista agora. Os pontos e os efeitos das
     * alternativas ficam de fora até o fim do evento — senão bastaria abrir o
     * DevTools para saber a resposta certa, e ela vale para as duas fases.
     */
    public function question(Question $question, bool $withAnswerKey = false, bool $forMaster = false): array
    {
        $data = [
            'id' => $question->id,
            'phase' => $question->phase,
            'round' => $question->round,
            'mode' => $question->mode,
            'label' => $question->label,
            'source' => $question->source,
            'title' => $question->title,
            'context' => $question->context,
            'duration' => $question->duration,
            'is_bonus' => $question->is_bonus,
            // a rodada final: sem alternativas, pontuada pelo facilitador
            'manual_scoring' => $question->isManual(),
            'options' => $question->options->map(fn ($o) => array_filter([
                'id' => $o->id,
                'text' => $o->text,
                'order' => $o->order,
                'effect' => ($withAnswerKey || $forMaster) ? $o->effect : null,
                'points' => ($withAnswerKey || $forMaster) ? $o->points : null,
                // `color` vem de `colorForPoints()` — é a régua em hexadecimal
                'color' => ($withAnswerKey || $forMaster) ? $o->color : null,
            ], fn ($value) => $value !== null))->all(),
        ];

        if ($forMaster) {
            // o viés esperado é nota de condução, nunca vai ao telão
            $data['bias_note'] = $question->bias_note;
        }

        return $data;
    }

    public function participant(Participant $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'gender' => $p->gender,
            'avatar_seed' => $p->avatar_seed,
            'online' => $p->isOnline(),
        ];
    }

    /**
     * Fase 1: todo mundo responde. Fase 2: só o representante da mesa — e
     * enquanto ninguém assumiu, qualquer um da mesa pode.
     *
     * A rodada final é a exceção: não há alternativa a registrar, a mesa
     * discute e o facilitador lança a pontuação. Ninguém responde pelo celular.
     *
     * Quem o facilitador bloqueou não assume a mesa. A Fase 1 não muda para
     * ele — continua votando e continua somando —, mas o posto de
     * representante é a única forma de uma pessoa aparecer *falando pela mesa*,
     * e é justamente isso que o bloqueio existe para evitar. Sem exceção
     * visível: para ele a tela é a de quem não é representante, a mesma que
     * qualquer colega vê quando outra pessoa assumiu.
     */
    public function canAnswer(
        Event $event,
        Participant $participant,
        EventTable $table,
        ?Question $question = null,
    ): bool {
        if ($event->isIndividualPhase()) {
            return true;
        }

        $question ??= $event->currentQuestion();

        if ($question?->isManual() || $participant->isBlocked()) {
            return false;
        }

        return $table->representative_id === null
            || $table->representative_id === $participant->id;
    }

    /** @return Collection<int, int> */
    protected function voterIds(Question $question): Collection
    {
        if (! $question->isIndividual()) {
            return collect();
        }

        return ParticipantVote::where('question_id', $question->id)->pluck('participant_id');
    }

    /** @return Collection<int, int> */
    protected function answeredTableIds(Question $question): Collection
    {
        return TableVote::where('question_id', $question->id)->pluck('event_table_id');
    }

    /**
     * Cores da mesa no mapa: cinza = aguardando, azul = votando,
     * amarelo = acabando o tempo, verde = todos votaram, vermelho = sem conexão.
     */
    protected function tableState(
        Event $event,
        Collection $participants,
        int $online,
        bool $done,
    ): string {
        if ($done) {
            return 'done';
        }

        if ($participants->isNotEmpty() && $online === 0) {
            return 'offline';
        }

        if (! $event->isAcceptingVotes()) {
            return 'idle';
        }

        $remaining = $event->remainingSeconds();
        $duration = max(1, $event->round_duration);

        return $remaining / $duration <= 0.25 ? 'warning' : 'discussing';
    }
}
