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
 * 2. **Nada de gabarito antes do fim do evento.** Como a Fase 2 repete as
 *    perguntas da Fase 1, revelar a melhor alternativa numa rodada entregaria a
 *    resposta da outra. A revelação de cada rodada mostra a *divergência* — a
 *    distribuição dos votos — e nada mais. Melhor alternativa, pontos e efeitos
 *    só saem do servidor quando já não dá para usá-los.
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
                'can_answer' => $this->canAnswer($event, $participant, $table),
                'has_voted' => $mine !== null,
                'voted_option_id' => $mine?->option_id,
                // Os pontos da própria escolha entregam o gabarito tão bem
                // quanto o selo de "melhor decisão" — e o total acumulado
                // entrega por diferença, rodada a rodada. Os dois só aparecem
                // no fim.
                'round_points' => $key ? $mine?->points : null,
                'total_points' => $key ? $this->scores->participantPoints($event, $participant) : null,
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
            ->map(function (EventTable $table) use ($event, $voters, $answeredTables) {
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
                        ])
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
        if ($this->showsAnswerKey($event, $forMaster)) {
            $payload['table_ranking'] = $this->scores->tableRanking($event);
            $payload['phase_comparison'] = $this->scores->phaseComparison($event);
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
            $payload['individual_ranking'] = $this->scores->individualRanking(
                $event,
                limit: $forMaster ? 0 : 10,
            );
        }

        if ($forMaster) {
            $payload['needs_tie_break'] = $this->scores->needsTieBreak($payload['table_ranking']);
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
            'unit' => $question->isIndividual() ? 'participants' : 'tables',
            'total_votes' => $total,
            'has_answer_key' => $withAnswerKey,
            'options' => $rows->values()->all(),
        ];
    }

    /**
     * O gabarito completo, liberado só no encerramento.
     *
     * As duas fases fazem as mesmas perguntas, então cada rodada aparece uma
     * vez só, com as duas leituras lado a lado: quanta gente acertou sozinha na
     * Fase 1 e quantas mesas acertaram juntas na Fase 2. Essa comparação é o
     * que a dinâmica inteira existe para produzir.
     *
     * @return array<int, array<string, mixed>>
     */
    public function answerKey(Event $event): array
    {
        $questions = $event->questions()->with('options')->where('is_bonus', false)->get();
        $ids = $questions->pluck('id');

        $tally = fn (string $model) => $model::whereIn('question_id', $ids)
            ->selectRaw('question_id, option_id, count(*) as total')
            ->groupBy('question_id', 'option_id')
            ->get()
            ->mapWithKeys(fn ($row) => ["{$row->question_id}:{$row->option_id}" => (int) $row->total]);

        $byPerson = $tally(ParticipantVote::class);
        $byTable = $tally(TableVote::class);

        return $questions
            ->groupBy('round')
            ->sortKeys()
            ->map(function (Collection $round) use ($byPerson, $byTable) {
                $one = $round->firstWhere('phase', 1) ?? $round->first();
                $two = $round->firstWhere('phase', 2);
                // as alternativas espelhadas casam pela ordem, não pelo id
                $mirrors = $two?->options->keyBy('order') ?? collect();
                $best = $one->bestOption();

                $options = $one->options->map(function ($option) use ($one, $two, $mirrors, $byPerson, $byTable, $best) {
                    $mirror = $mirrors->get($option->order);

                    return [
                        'text' => $option->text,
                        'effect' => $option->effect,
                        'points' => $option->points,
                        'color' => $option->color,
                        'is_best' => $best !== null && $option->id === $best->id,
                        'individual_votes' => $byPerson["{$one->id}:{$option->id}"] ?? 0,
                        'table_votes' => $two && $mirror ? ($byTable["{$two->id}:{$mirror->id}"] ?? 0) : 0,
                    ];
                });

                $people = $options->sum('individual_votes');
                $tables = $options->sum('table_votes');
                $winner = $options->firstWhere('is_best', true);

                return [
                    'round' => $one->round,
                    'label' => $one->label,
                    'title' => $one->title,
                    'best_text' => $winner['text'] ?? null,
                    'best_effect' => $winner['effect'] ?? null,
                    'best_points' => $winner['points'] ?? null,
                    // sozinho contra junto, na mesma pergunta
                    'individual_accuracy' => $people > 0
                        ? (int) round(($winner['individual_votes'] ?? 0) / $people * 100)
                        : null,
                    'table_accuracy' => $tables > 0
                        ? (int) round(($winner['table_votes'] ?? 0) / $tables * 100)
                        : null,
                    'options' => $options->sortByDesc('points')->values()->all(),
                ];
            })
            ->values()
            ->all();
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
            'title' => $question->title,
            'context' => $question->context,
            'duration' => $question->duration,
            'is_bonus' => $question->is_bonus,
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
     */
    public function canAnswer(Event $event, Participant $participant, EventTable $table): bool
    {
        if ($event->isIndividualPhase()) {
            return true;
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
