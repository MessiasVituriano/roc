<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Mission;
use App\Models\Participant;
use RuntimeException;

/**
 * A máquina de estados do evento. Cada botão do painel master é exatamente um
 * método aqui.
 *
 * Roteiro: Fase 1 com 5 rodadas individuais (~20s de voto + revelação a cada
 * rodada), virada de fase revelando o placar por grupo de missão, e Fase 2
 * repetindo as mesmas 5 perguntas, agora decididas em consenso pela mesa.
 */
class EventFlowService
{
    /** Abre as portas: participantes podem entrar e escolher a mesa. */
    public function open(Event $event): Event
    {
        $event->update([
            'status' => Event::STATUS_OPEN,
            'started_at' => $event->started_at ?? now(),
            'round_status' => Event::ROUND_IDLE,
        ]);

        return $event->refresh();
    }

    /**
     * Sorteia as missões da Fase 1. Distribuição circular em vez de aleatória
     * pura: com 4 missões e ~150 pessoas, o sorteio puro deixaria grupos de
     * tamanhos bem diferentes e o placar por missão ficaria difícil de ler.
     *
     * @return int quantas pessoas receberam missão agora
     */
    public function assignMissions(Event $event, bool $reassign = false): int
    {
        $missions = $event->missions()->orderBy('id')->get();

        if ($missions->isEmpty()) {
            throw new RuntimeException('Nenhuma missão cadastrada para este evento.');
        }

        $query = $event->participants()->orderBy('id');

        if (! $reassign) {
            $query->whereNull('mission_id');
        }

        $people = $query->get();
        // continua a rodar a partir de onde a distribuição parou, para quem
        // chega atrasado não desequilibrar os grupos
        $offset = $reassign ? 0 : $event->participants()->whereNotNull('mission_id')->count();

        foreach ($people as $index => $person) {
            $person->forceFill([
                'mission_id' => $missions[($offset + $index) % $missions->count()]->id,
            ])->saveQuietly();
        }

        return $people->count();
    }

    /** Garante missão para quem entrou depois do sorteio. */
    public function assignMissionTo(Event $event, Participant $participant): ?Mission
    {
        if ($participant->mission_id) {
            return $participant->mission;
        }

        $missions = $event->missions()->orderBy('id')->get();

        if ($missions->isEmpty()) {
            return null;
        }

        // entra no menor grupo, mantendo o equilíbrio
        $counts = $event->participants()
            ->whereNotNull('mission_id')
            ->selectRaw('mission_id, count(*) as total')
            ->groupBy('mission_id')
            ->pluck('total', 'mission_id');

        $mission = $missions->sortBy(fn (Mission $m) => (int) ($counts[$m->id] ?? 0))->first();

        $participant->forceFill(['mission_id' => $mission->id])->saveQuietly();

        return $mission;
    }

    /** Abre a votação da rodada atual. */
    public function startRound(Event $event, ?int $duration = null): Event
    {
        $question = $event->questionFor($event->phase, $event->current_round);

        if (! $question) {
            throw new RuntimeException('Nenhuma pergunta cadastrada para esta rodada.');
        }

        // quem entrou depois ainda precisa de missão antes de votar
        if ($event->isIndividualPhase()) {
            $this->assignMissions($event);
        }

        $seconds = $duration ?? $question->duration;

        $event->update([
            'status' => Event::STATUS_RUNNING,
            'current_question_id' => $question->id,
            'round_status' => Event::ROUND_VOTING,
            'round_duration' => $seconds,
            'round_started_at' => now(),
            'round_ends_at' => now()->addSeconds($seconds),
            'started_at' => $event->started_at ?? now(),
        ]);

        return $event->refresh();
    }

    /** Fecha a votação sem revelar — o telão continua na pergunta. */
    public function closeRound(Event $event): Event
    {
        $event->update(['round_ends_at' => now()]);

        return $event->refresh();
    }

    /** O clique do facilitador: consequência e pontos vão ao telão. */
    public function reveal(Event $event): Event
    {
        $event->update([
            'round_status' => Event::ROUND_REVEALED,
            'round_ends_at' => now(),
        ]);

        return $event->refresh();
    }

    /** Volta o telão para a votação — usado se a revelação foi cedo demais. */
    public function unreveal(Event $event): Event
    {
        $event->update(['round_status' => Event::ROUND_IDLE]);

        return $event->refresh();
    }

    /**
     * Próxima rodada. No fim da Fase 1 apenas para: a virada de fase (revelar
     * o placar por missão) é um passo deliberado do facilitador.
     */
    public function nextRound(Event $event): Event
    {
        $next = $event->questionFor($event->phase, $event->current_round + 1);

        if ($next) {
            $event->update([
                'current_round' => $next->round,
                'current_question_id' => $next->id,
                'round_status' => Event::ROUND_IDLE,
                'round_started_at' => null,
                'round_ends_at' => null,
            ]);

            return $event->refresh();
        }

        $event->update([
            'round_status' => Event::ROUND_IDLE,
            'round_started_at' => null,
            'round_ends_at' => null,
        ]);

        return $event->refresh();
    }

    /** A virada de fase: o placar por grupo de missão vai ao telão. */
    public function revealMissions(Event $event): Event
    {
        $event->update(['missions_revealed' => true]);

        return $event->refresh();
    }

    public function hideMissions(Event $event): Event
    {
        $event->update(['missions_revealed' => false]);

        return $event->refresh();
    }

    /**
     * O fecho: abre o gabarito e o comparativo entre as fases.
     *
     * Passo separado do encerramento de propósito. Encerrar joga os celulares
     * na tela de "obrigado"; este clique mantém todo mundo na sala enquanto o
     * telão mostra qual era a melhor decisão de cada rodada e quanto a mesa
     * rendeu a mais que as decisões isoladas.
     */
    public function revealAnswers(Event $event): Event
    {
        $event->update([
            'answers_revealed' => true,
            'round_status' => Event::ROUND_IDLE,
            'round_ends_at' => null,
        ]);

        return $event->refresh();
    }

    public function hideAnswers(Event $event): Event
    {
        $event->update(['answers_revealed' => false]);

        return $event->refresh();
    }

    /** Passa para a Fase 2, ou encerra se já estiver nela. */
    public function nextPhase(Event $event): Event
    {
        if ($event->phase >= Event::LAST_PHASE) {
            return $this->end($event);
        }

        $first = $event->questionFor($event->phase + 1, 1);

        $event->update([
            'phase' => $event->phase + 1,
            'current_round' => 1,
            'current_question_id' => $first?->id,
            'round_status' => Event::ROUND_IDLE,
            'round_started_at' => null,
            'round_ends_at' => null,
        ]);

        return $event->refresh();
    }

    /** Carrega a pergunta bônus de desempate (4º critério de vitória). */
    public function loadBonusRound(Event $event): Event
    {
        $bonus = $event->questions()->where('is_bonus', true)->first();

        if (! $bonus) {
            throw new RuntimeException('Nenhuma pergunta de desempate cadastrada.');
        }

        $event->update([
            'phase' => $bonus->phase,
            'current_round' => $bonus->round,
            'current_question_id' => $bonus->id,
            'round_status' => Event::ROUND_IDLE,
            'round_started_at' => null,
            'round_ends_at' => null,
        ]);

        return $event->refresh();
    }

    public function end(Event $event): Event
    {
        $event->update([
            'status' => Event::STATUS_FINISHED,
            'round_status' => Event::ROUND_IDLE,
            'finished_at' => now(),
            'round_ends_at' => null,
        ]);

        return $event->refresh();
    }

    /** Estende a rodada sem reiniciá-la. */
    public function addTime(Event $event, int $seconds): Event
    {
        if ($event->round_status !== Event::ROUND_VOTING || ! $event->round_ends_at) {
            return $event;
        }

        $event->update([
            'round_ends_at' => $event->round_ends_at->addSeconds($seconds),
            'round_duration' => $event->round_duration + $seconds,
        ]);

        return $event->refresh();
    }
}
