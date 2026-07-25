<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventTable;
use App\Models\Mission;
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

        $rows = $event->participants()
            ->with(['mission', 'table'])
            ->get()
            ->map(function (Participant $p) use ($totals) {
                $row = $totals->get($p->id);

                return [
                    'participant_id' => $p->id,
                    'name' => $p->name,
                    'avatar_seed' => $p->avatar_seed,
                    'gender' => $p->gender,
                    'table' => $p->table?->name,
                    'table_icon' => $p->table?->icon,
                    'mission' => $p->mission?->name,
                    'mission_color' => $p->mission?->color,
                    'answered' => (int) ($row->answered ?? 0),
                    'points' => (int) ($row->total ?? 0),
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
     */
    public function missionRanking(Event $event): array
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

        return $event->missions()
            ->orderBy('id')
            ->get()
            ->map(function (Mission $mission) use ($totals, $people) {
                $row = $totals->get($mission->id);
                $votes = (int) ($row->votes ?? 0);
                $points = (int) ($row->total ?? 0);

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

        $rows = $event->tables()
            ->withCount('participants')
            ->orderBy('id')
            ->get()
            ->map(function (EventTable $table) use ($phaseOne, $phaseTwo) {
                $one = $phaseOne->get($table->id);
                $two = $phaseTwo->get($table->id);

                $oneVotes = (int) ($one->votes ?? 0);
                $onePoints = (int) ($one->total ?? 0);
                $twoVotes = (int) ($two->votes ?? 0);
                $twoPoints = (int) ($two->total ?? 0);

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
                    'phase_two_points' => $twoPoints,
                    'phase_two_average' => round($twoAverage, 1),
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
