<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventTable;
use App\Models\Mission;
use App\Models\Option;
use App\Models\Participant;
use App\Models\ParticipantVote;
use App\Models\TableVote;
use Illuminate\Support\Collection;

/**
 * As três camadas de placar do painel master, e o critério de vitória.
 *
 * Os pontos ficam congelados na linha do voto (`points`), então editar a régua
 * de uma alternativa no meio do evento não reescreve o passado.
 */
class ScoreService
{
    /** Régua da dinâmica: +150 (melhor decisão), +80, 0, -50. */
    public const MAX_POINTS = 150;

    /**
     * A melhor alternativa de cada pergunta, por `question_id`.
     *
     * "Acertou" é sobre a alternativa, não sobre o número: os pontos ficam
     * congelados na linha do voto, então comparar valores confundiria um voto
     * antigo com uma régua editada depois. Comparar o `option_id` não confunde.
     *
     * @return Collection<int, int>
     */
    protected function bestOptionIds(Event $event): Collection
    {
        return Option::query()
            ->join('questions', 'questions.id', '=', 'options.question_id')
            ->where('questions.event_id', $event->id)
            ->orderByDesc('options.points')
            ->get(['options.id', 'options.question_id'])
            // ordenado por pontos desc, o primeiro de cada pergunta é o melhor
            ->unique('question_id')
            ->pluck('id', 'question_id');
    }

    /**
     * Quantos acertos cada chave (pessoa ou mesa) fez numa fase.
     *
     * @return Collection<int, int>
     */
    protected function correctBy(Event $event, string $model, string $groupBy, int $phase): Collection
    {
        $table = (new $model)->getTable();
        $best = $this->bestOptionIds($event);

        if ($best->isEmpty()) {
            return collect();
        }

        return $model::query()
            ->join('questions', 'questions.id', '=', "{$table}.question_id")
            ->where('questions.event_id', $event->id)
            ->where('questions.phase', $phase)
            ->whereIn("{$table}.option_id", $best->values())
            ->selectRaw("{$table}.{$groupBy}, count(*) as total")
            ->groupBy("{$table}.{$groupBy}")
            ->pluck('total', $groupBy);
    }

    /** Acerto em percentual, ou null quando ainda não houve voto. */
    protected function accuracy(int $correct, int $answered): ?int
    {
        return $answered > 0 ? (int) round($correct / $answered * 100) : null;
    }

    /**
     * Camada 1 — ranking individual, somando as rodadas da Fase 1.
     */
    public function individualRanking(Event $event, int $limit = 0): array
    {
        $totals = ParticipantVote::query()
            ->join('questions', 'questions.id', '=', 'participant_votes.question_id')
            ->where('questions.event_id', $event->id)
            ->where('questions.phase', 1)
            ->selectRaw('participant_votes.participant_id, sum(participant_votes.points) as total, count(*) as answered')
            ->groupBy('participant_votes.participant_id')
            ->get()
            ->keyBy('participant_id');

        $correct = $this->correctBy($event, ParticipantVote::class, 'participant_id', phase: 1);
        $rounds = $event->questions()->where('phase', 1)->where('is_bonus', false)->count();

        // A Fase 2 não tem voto individual — a mesa decide por todos. Para ver
        // uma pessoa por inteiro é preciso somar o que ela fez sozinha com o que
        // a mesa dela fez junto. O valor da mesa se repete em cada membro de
        // propósito: é o que cada um leva da decisão coletiva, não uma parcela.
        $tablePhaseTwo = TableVote::query()
            ->join('questions', 'questions.id', '=', 'table_votes.question_id')
            ->where('questions.event_id', $event->id)
            ->where('questions.phase', 2)
            ->selectRaw('table_votes.event_table_id, sum(table_votes.points) as total, count(*) as votes')
            ->groupBy('table_votes.event_table_id')
            ->get()
            ->keyBy('event_table_id');

        $tableCorrect = $this->correctBy($event, TableVote::class, 'event_table_id', phase: 2);

        $rows = $event->participants()
            ->with(['mission', 'table'])
            ->get()
            ->map(function (Participant $p) use ($totals, $correct, $rounds, $tablePhaseTwo, $tableCorrect) {
                $row = $totals->get($p->id);
                $answered = (int) ($row->answered ?? 0);
                $hits = (int) ($correct[$p->id] ?? 0);

                $mesa = $tablePhaseTwo->get($p->event_table_id);
                $mesaPoints = (int) ($mesa->total ?? 0);
                $mesaVotes = (int) ($mesa->votes ?? 0);
                $mesaHits = (int) ($tableCorrect[$p->event_table_id] ?? 0);
                $own = (int) ($row->total ?? 0);

                return [
                    'participant_id' => $p->id,
                    'name' => $p->name,
                    'avatar_seed' => $p->avatar_seed,
                    'gender' => $p->gender,
                    'table' => $p->table?->name,
                    'table_icon' => $p->table?->icon,
                    'mission' => $p->mission?->name,
                    'mission_color' => $p->mission?->color,
                    'answered' => $answered,
                    // acertos da Fase 1: quantas vezes escolheu a melhor decisão
                    'correct' => $hits,
                    'rounds' => $rounds,
                    'accuracy' => $this->accuracy($hits, $answered),
                    'points' => $own,

                    // o que a mesa desta pessoa fez na Fase 2
                    'table_points' => $mesaPoints,
                    'table_votes' => $mesaVotes,
                    'table_correct' => $mesaHits,
                    'table_accuracy' => $this->accuracy($mesaHits, $mesaVotes),

                    // a pessoa por inteiro: decidindo sozinha + decidindo junto
                    'combined_points' => $own + $mesaPoints,
                ];
            })
            ->sortByDesc('points')
            ->values();

        $ranked = $rows->map(fn ($row, $i) => $row + ['position' => $i + 1]);

        return ($limit > 0 ? $ranked->take($limit) : $ranked)->all();
    }

    /**
     * Camada 2 — total por grupo de missão. É o recorte que revela o viés na
     * virada de fase: mesma régua, decisões diferentes.
     *
     * Os acertos ficam atrás de `$withAnswerKey`: a virada acontece antes da
     * Fase 2, e num grupo pequeno "100% de acerto" somado à distribuição da
     * rodada identifica a alternativa certa. A média de pontos continua
     * aparecendo — é o "ahá" da virada e não é invertível do mesmo jeito.
     */
    public function missionRanking(Event $event, bool $withAnswerKey = false): array
    {
        $totals = ParticipantVote::query()
            ->join('questions', 'questions.id', '=', 'participant_votes.question_id')
            ->where('questions.event_id', $event->id)
            ->where('questions.phase', 1)
            ->whereNotNull('participant_votes.mission_id')
            ->selectRaw('participant_votes.mission_id, sum(participant_votes.points) as total, count(*) as votes')
            ->groupBy('participant_votes.mission_id')
            ->get()
            ->keyBy('mission_id');

        $people = $event->participants()
            ->whereNotNull('mission_id')
            ->selectRaw('mission_id, count(*) as total')
            ->groupBy('mission_id')
            ->pluck('total', 'mission_id');

        // acertos por grupo de missão: é aqui que o viés fica mais explícito —
        // quem carrega a missão errada para o cenário acerta menos
        $correct = $withAnswerKey
            ? $this->correctBy($event, ParticipantVote::class, 'mission_id', phase: 1)
            : collect();

        return $event->missions()
            ->orderBy('id')
            ->get()
            ->map(function (Mission $mission) use ($totals, $people, $correct, $withAnswerKey) {
                $row = $totals->get($mission->id);
                $votes = (int) ($row->votes ?? 0);
                $points = (int) ($row->total ?? 0);
                $hits = (int) ($correct[$mission->id] ?? 0);

                return [
                    'mission_id' => $mission->id,
                    'key' => $mission->key,
                    'name' => $mission->name,
                    'statement' => $mission->statement,
                    'icon' => $mission->icon,
                    'color' => $mission->color,
                    'participants' => (int) ($people[$mission->id] ?? 0),
                    'votes' => $votes,
                    'points' => $points,
                    'correct' => $withAnswerKey ? $hits : null,
                    'accuracy' => $withAnswerKey ? $this->accuracy($hits, $votes) : null,
                    // a média por voto é o que torna os grupos comparáveis mesmo
                    // com quantidades diferentes de gente em cada missão
                    'average' => $votes > 0 ? round($points / $votes, 1) : 0.0,
                ];
            })
            ->sortByDesc('average')
            ->values()
            ->all();
    }

    /**
     * Camada 3 — total por mesa: soma dos membros na Fase 1 + decisão conjunta
     * da Fase 2 + evolução. Já ordenada pelo critério de vitória.
     */
    public function tableRanking(Event $event): array
    {
        $phaseOne = ParticipantVote::query()
            ->join('questions', 'questions.id', '=', 'participant_votes.question_id')
            ->where('questions.event_id', $event->id)
            ->where('questions.phase', 1)
            ->selectRaw('participant_votes.event_table_id, sum(participant_votes.points) as total, count(*) as votes')
            ->groupBy('participant_votes.event_table_id')
            ->get()
            ->keyBy('event_table_id');

        $phaseTwo = TableVote::query()
            ->join('questions', 'questions.id', '=', 'table_votes.question_id')
            ->where('questions.event_id', $event->id)
            ->where('questions.phase', 2)
            ->selectRaw('table_votes.event_table_id, sum(table_votes.points) as total, count(*) as votes')
            ->groupBy('table_votes.event_table_id')
            ->get()
            ->keyBy('event_table_id');

        // acertos das duas fases: na 1 são os votos individuais dos membros,
        // na 2 são as decisões conjuntas da mesa
        $oneCorrect = $this->correctBy($event, ParticipantVote::class, 'event_table_id', phase: 1);
        $twoCorrect = $this->correctBy($event, TableVote::class, 'event_table_id', phase: 2);

        $rows = $event->tables()
            ->withCount('participants')
            ->orderBy('id')
            ->get()
            ->map(function (EventTable $table) use ($phaseOne, $phaseTwo, $oneCorrect, $twoCorrect) {
                $one = $phaseOne->get($table->id);
                $two = $phaseTwo->get($table->id);

                $oneVotes = (int) ($one->votes ?? 0);
                $onePoints = (int) ($one->total ?? 0);
                $twoVotes = (int) ($two->votes ?? 0);
                $twoPoints = (int) ($two->total ?? 0);

                $oneHits = (int) ($oneCorrect[$table->id] ?? 0);
                $twoHits = (int) ($twoCorrect[$table->id] ?? 0);

                // Evolução: quanto a decisão conjunta rendeu por rodada frente
                // ao que os membros vinham rendendo por rodada sozinhos.
                $oneAverage = $oneVotes > 0 ? $onePoints / $oneVotes : 0.0;
                $twoAverage = $twoVotes > 0 ? $twoPoints / $twoVotes : 0.0;
                $delta = $twoAverage - $oneAverage;

                return [
                    'table_id' => $table->id,
                    'name' => $table->name,
                    'icon' => $table->icon,
                    'color' => $table->color,
                    'participants' => $table->participants_count,
                    'phase_one_points' => $onePoints,
                    'phase_one_average' => round($oneAverage, 1),
                    'phase_one_votes' => $oneVotes,
                    'phase_one_correct' => $oneHits,
                    'phase_one_accuracy' => $this->accuracy($oneHits, $oneVotes),
                    'phase_two_points' => $twoPoints,
                    'phase_two_average' => round($twoAverage, 1),
                    'phase_two_votes' => $twoVotes,
                    'phase_two_correct' => $twoHits,
                    'phase_two_accuracy' => $this->accuracy($twoHits, $twoVotes),
                    'total_points' => $onePoints + $twoPoints,
                    'evolution' => round($delta, 1),
                    // percentual sobre a régua máxima, para caber numa barra
                    'evolution_percent' => (int) round($delta / self::MAX_POINTS * 100),
                    'answered_phase_two' => $twoVotes > 0,
                ];
            })
            ->all();

        return $this->applyTieBreak($rows);
    }

    /**
     * Critério de vitória, em 4 níveis:
     *   1. maior Valor Gerado Total (Fase 1 + Fase 2)
     *   2. maior pontuação na Fase 2
     *   3. maior evolução Fase 1 → Fase 2
     *   4. rodada de desempate ao vivo (fora do sistema: o facilitador roda a
     *      pergunta bônus, cujo resultado entra como pontuação de Fase 2)
     *
     * `tied_with_leader` marca quem chegou ao nível 4 ainda empatado — é o
     * gatilho para o facilitador rodar a pergunta bônus.
     */
    protected function applyTieBreak(array $rows): array
    {
        usort($rows, function (array $a, array $b) {
            return [$b['total_points'], $b['phase_two_points'], $b['evolution']]
                <=> [$a['total_points'], $a['phase_two_points'], $a['evolution']];
        });

        $leader = $rows[0] ?? null;

        return collect($rows)
            ->map(function (array $row, int $i) use ($leader) {
                $tied = $leader !== null
                    && $row['total_points'] === $leader['total_points']
                    && $row['phase_two_points'] === $leader['phase_two_points']
                    && $row['evolution'] === $leader['evolution'];

                return $row + [
                    'position' => $i + 1,
                    // empate que sobrevive aos três primeiros critérios
                    'tied_with_leader' => $tied && $i > 0,
                ];
            })
            ->all();
    }

    /**
     * O comparativo entre as fases — a tese da dinâmica em números.
     *
     * Só faz sentido porque as duas fases fazem as **mesmas perguntas**: a
     * diferença de acerto entre elas isola uma variável só, decidir sozinho
     * contra decidir junto. Se as perguntas fossem outras, este número não
     * significaria nada.
     */
    public function phaseComparison(Event $event): array
    {
        $individual = $this->phaseTally($event, ParticipantVote::class, phase: 1);
        $table = $this->phaseTally($event, TableVote::class, phase: 2);

        $bothScored = $individual['accuracy'] !== null && $table['accuracy'] !== null;

        return [
            'individual' => $individual,
            'table' => $table,
            'accuracy_delta' => $bothScored ? $table['accuracy'] - $individual['accuracy'] : null,
            'average_delta' => round($table['average'] - $individual['average'], 1),
            // a tese se confirmou nesta sala?
            'consensus_won' => $bothScored ? $table['accuracy'] > $individual['accuracy'] : null,
        ];
    }

    /** Votos, acertos e pontos de uma fase inteira. */
    protected function phaseTally(Event $event, string $model, int $phase): array
    {
        $table = (new $model)->getTable();

        $base = fn () => $model::query()
            ->join('questions', 'questions.id', '=', "{$table}.question_id")
            ->where('questions.event_id', $event->id)
            ->where('questions.phase', $phase);

        $row = $base()
            ->selectRaw("count(*) as votes, coalesce(sum({$table}.points), 0) as points")
            ->first();

        $votes = (int) ($row->votes ?? 0);
        $points = (int) ($row->points ?? 0);
        $best = $this->bestOptionIds($event);

        $correct = $best->isEmpty()
            ? 0
            : $base()->whereIn("{$table}.option_id", $best->values())->count();

        return [
            'votes' => $votes,
            'correct' => $correct,
            'accuracy' => $this->accuracy($correct, $votes),
            'points' => $points,
            'average' => $votes > 0 ? round($points / $votes, 1) : 0.0,
        ];
    }

    /** Acertos do participante na Fase 1, para a própria tela dele. */
    public function participantCorrect(Event $event, Participant $participant): int
    {
        $best = $this->bestOptionIds($event);

        if ($best->isEmpty()) {
            return 0;
        }

        return ParticipantVote::query()
            ->join('questions', 'questions.id', '=', 'participant_votes.question_id')
            ->where('questions.event_id', $event->id)
            ->where('questions.phase', 1)
            ->where('participant_votes.participant_id', $participant->id)
            ->whereIn('participant_votes.option_id', $best->values())
            ->count();
    }

    /** Pontos do participante na Fase 1, para a própria tela dele. */
    public function participantPoints(Event $event, Participant $participant): int
    {
        return (int) ParticipantVote::query()
            ->join('questions', 'questions.id', '=', 'participant_votes.question_id')
            ->where('questions.event_id', $event->id)
            ->where('questions.phase', 1)
            ->where('participant_votes.participant_id', $participant->id)
            ->sum('participant_votes.points');
    }

    /**
     * Resumo curto para o telão: quantas mesas ainda empatadas na liderança.
     *
     * @param  array<int, array<string, mixed>>  $tableRanking
     */
    public function needsTieBreak(array $tableRanking): bool
    {
        return collect($tableRanking)->contains('tied_with_leader', true);
    }

    /** @return Collection<int, Mission> */
    public function missions(Event $event): Collection
    {
        return $event->missions()->orderBy('id')->get();
    }
}
