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
 * Regra central: **nada de resultado antes do clique do facilitador.** Durante
 * a votação o telão vê só quantos votos chegaram, nunca a distribuição.
 */
class EventStateService
{
    public function __construct(protected ScoreService $scores) {}

    public function activeEvent(): ?Event
    {
        return Event::query()->orderByDesc('id')->first();
    }

    /**
     * Payload leve, consultado a cada segundo pela tela do participante.
     */
    public function status(Event $event, ?Participant $participant = null): array
    {
        $question = $event->currentQuestion();
        $revealed = $event->round_status === Event::ROUND_REVEALED;

        $payload = [
            'event' => $this->event($event),
            'timer' => $this->timer($event),
            'question' => $question ? $this->question($question, revealed: $revealed) : null,
            'progress' => $this->progress($event, $question),
            'results' => $revealed && $question ? $this->results($question) : null,
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
                // os pontos da própria escolha só aparecem depois da revelação
                'round_points' => $revealed ? $mine?->points : null,
                'total_points' => $this->scores->participantPoints($event, $participant),
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
            'question' => $question ? $this->question($question, revealed: $revealed, forMaster: $forMaster) : null,
            'progress' => $this->progress($event, $question),
            'results' => $revealed && $question ? $this->results($question) : null,
            'tables' => $tables,
            'stats' => [
                'participants' => $event->participants()->count(),
                'connected' => $event->participants()
                    ->where('last_seen', '>', now()->subSeconds(config('live.presence_ttl')))
                    ->count(),
                'tables' => count($tables),
            ],
            // camada 3 — sempre disponível, é o placar do critério de vitória
            'table_ranking' => $this->scores->tableRanking($event),
        ];

        // camada 2 — o viés por missão só vai ao telão na virada de fase
        if ($event->missions_revealed || $forMaster) {
            $payload['mission_ranking'] = $this->scores->missionRanking($event);
        }

        if ($forMaster) {
            // camada 1 — ranking individual, só para o facilitador
            $payload['individual_ranking'] = $this->scores->individualRanking($event, limit: 20);
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
     * Distribuição da rodada, com os pontos de cada alternativa. Só é chamado
     * depois que o facilitador revelou.
     */
    public function results(Question $question): array
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
        $best = $question->bestOption();

        $rows = $question->options->map(fn ($option) => [
            'option_id' => $option->id,
            'text' => $option->text,
            'effect' => $option->effect,
            'points' => $option->points,
            'color' => $option->color,
            'is_best' => $best !== null && $option->id === $best->id,
            'votes' => (int) ($counts[$option->id] ?? 0),
            'percent' => $total > 0 ? (int) round(($counts[$option->id] ?? 0) / $total * 100) : 0,
        ])
            // na revelação a ordem que importa é a da régua de pontos, não a
            // dos votos: a plateia precisa ver qual era a melhor decisão
            ->sortByDesc('points')
            ->values()
            ->all();

        return [
            'mode' => $question->mode,
            'unit' => $question->isIndividual() ? 'participants' : 'tables',
            'total_votes' => $total,
            'options' => $rows,
        ];
    }

    /**
     * A pergunta como ela pode ser vista agora. Antes da revelação, os pontos
     * e os efeitos das alternativas são omitidos — senão bastaria abrir o
     * DevTools para saber a resposta certa.
     */
    public function question(Question $question, bool $revealed = false, bool $forMaster = false): array
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
                'color' => $o->color,
                'order' => $o->order,
                'effect' => ($revealed || $forMaster) ? $o->effect : null,
                'points' => ($revealed || $forMaster) ? $o->points : null,
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
