<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventTable;
use App\Models\Option;
use App\Models\Participant;
use App\Models\ParticipantVote;
use App\Models\TableVote;
use App\Services\EventFlowService;
use App\Services\EventStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ParticipantController extends Controller
{
    public function __construct(
        protected EventStateService $state,
        protected EventFlowService $flow,
    ) {}

    /** Landing payload for the join screen: which event, which tables. */
    public function bootstrap(): JsonResponse
    {
        $event = $this->state->activeEvent();

        if (! $event) {
            return response()->json(['event' => null, 'tables' => []]);
        }

        $tables = $event->tables()
            ->withCount('participants')
            ->orderBy('name')
            ->get()
            ->map(fn (EventTable $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'icon' => $t->icon,
                'color' => $t->color,
                'participants_count' => $t->participants_count,
            ]);

        return response()->json([
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'status' => $event->status,
            ],
            'tables' => $tables,
        ]);
    }

    public function join(Request $request): JsonResponse
    {
        $event = $this->state->activeEvent();

        abort_if(! $event, 404, 'Nenhum evento disponível.');
        // o cadastro fica aberto mesmo com o evento em rascunho: as pessoas se
        // inscrevem antes e esperam na sala de espera. Só um evento encerrado
        // recusa novas entradas.
        abort_if($event->status === Event::STATUS_FINISHED, 423, 'Este evento já foi encerrado.');

        // normalise before validating, so "ANA@x.com" collides with "ana@x.com"
        // at the validation layer instead of blowing up on the unique index
        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'email' => [
                'required', 'email:rfc', 'max:120',
                Rule::unique('participants', 'email')->where('event_id', $event->id),
            ],
            'gender' => ['required', Rule::in(['male', 'female', 'custom'])],
            'table_id' => ['required', Rule::exists('event_tables', 'id')->where('event_id', $event->id)],
            // optional: lets the join screen show the exact avatar the person will get
            'avatar_seed' => ['nullable', 'string', 'max:32'],
        ], [
            'email.unique' => 'Este e-mail já entrou na dinâmica.',
            'email.email' => 'Informe um e-mail válido.',
        ]);

        $participant = Participant::create([
            'event_id' => $event->id,
            'event_table_id' => $data['table_id'],
            'name' => trim($data['name']),
            'email' => $data['email'],
            'gender' => $data['gender'],
            'avatar_seed' => $data['avatar_seed'] ?? Str::random(12),
            'device_token' => Str::random(48),
            'connected' => true,
            'last_seen' => now(),
        ]);

        // The seed is frozen at join time, so the avatar never changes afterwards.
        if (! isset($data['avatar_seed'])) {
            $participant->update(['avatar_seed' => 'p'.$participant->id]);
        }

        // a missão da Fase 1 é sorteada já na entrada, mantendo os grupos
        // equilibrados mesmo com quem chega atrasado
        $this->flow->assignMissionTo($event, $participant);
        $participant->refresh()->load('mission');

        return response()->json([
            'token' => $participant->device_token,
            'participant' => $this->state->participant($participant->refresh()),
            'status' => $this->state->status($event, $participant),
        ], 201);
    }

    /** Polled once per second by the participant screen. */
    public function status(Request $request): JsonResponse
    {
        $event = $this->state->activeEvent();

        if (! $event) {
            return response()->json(['event' => null]);
        }

        $participant = $this->resolveParticipant($request);

        if ($participant) {
            // Heartbeat — drives the connected/offline indicators everywhere.
            $participant->forceFill(['last_seen' => now(), 'connected' => true])->saveQuietly();
        }

        return response()->json($this->state->status($event, $participant));
    }

    public function timer(): JsonResponse
    {
        $event = $this->state->activeEvent();

        abort_if(! $event, 404);

        return response()->json($this->state->timer($event));
    }

    /**
     * Phase 2 only: claim the representative seat for the table. First claim
     * wins — the conditional update makes that atomic.
     */
    public function claimRepresentative(Request $request): JsonResponse
    {
        $event = $this->state->activeEvent();
        abort_if(! $event, 404);

        $participant = $this->resolveParticipant($request);
        abort_if(! $participant, 401, 'Participante não identificado.');
        abort_if($event->isIndividualPhase(), 409, 'A fase atual é individual.');
        // a rodada final não tem resposta a registrar — ninguém precisa assumir
        abort_if(
            (bool) $event->currentQuestion()?->isManual(),
            409,
            'A rodada final não tem resposta a registrar pela mesa.',
        );

        $claimed = EventTable::where('id', $participant->event_table_id)
            ->whereNull('representative_id')
            ->update(['representative_id' => $participant->id]);

        $table = EventTable::find($participant->event_table_id);

        return response()->json([
            'claimed' => $claimed === 1,
            'representative_id' => $table->representative_id,
            'status' => $this->state->status($event, $participant->refresh()),
        ]);
    }

    public function vote(Request $request): JsonResponse
    {
        $event = $this->state->activeEvent();
        abort_if(! $event, 404);

        $participant = $this->resolveParticipant($request);
        abort_if(! $participant, 401, 'Participante não identificado.');
        abort_if(! $event->isAcceptingVotes(), 409, 'A rodada não está aberta para votação.');

        $question = $event->currentQuestion();
        abort_if(! $question, 409, 'Nenhuma rodada em andamento.');
        abort_if(
            $question->isManual(),
            409,
            'A rodada final não se registra pelo celular: a mesa decide e o facilitador pontua.',
        );

        $data = $request->validate([
            'option_id' => ['required', Rule::exists('options', 'id')->where('question_id', $question->id)],
        ]);

        $option = Option::find($data['option_id']);

        if ($question->isIndividual()) {
            // uma linha por pessoa por rodada — o índice único é a garantia
            $vote = ParticipantVote::firstOrCreate(
                [
                    'participant_id' => $participant->id,
                    'question_id' => $question->id,
                ],
                [
                    'option_id' => $option->id,
                    'event_table_id' => $participant->event_table_id,
                    'mission_id' => $participant->mission_id,
                    // pontos congelados no voto: editar a régua depois não
                    // reescreve o placar já formado
                    'points' => $option->points,
                ],
            );
        } else {
            $table = $participant->table;

            abort_if(
                ! $this->state->canAnswer($event, $participant, $table, $question),
                403,
                'Apenas o representante da mesa responde nesta fase.',
            );

            if ($table->representative_id === null) {
                EventTable::where('id', $table->id)
                    ->whereNull('representative_id')
                    ->update(['representative_id' => $participant->id]);
                $table->refresh();

                abort_if(
                    $table->representative_id !== $participant->id,
                    403,
                    'Outra pessoa assumiu como representante da mesa.',
                );
            }

            $vote = TableVote::firstOrCreate(
                [
                    'event_table_id' => $table->id,
                    'question_id' => $question->id,
                ],
                [
                    'option_id' => $option->id,
                    'participant_id' => $participant->id,
                    'points' => $option->points,
                ],
            );
        }

        return response()->json([
            'accepted' => $vote->wasRecentlyCreated,
            'option_id' => $vote->option_id,
            // os pontos não voltam aqui: só depois da revelação do facilitador
            'status' => $this->state->status($event, $participant->refresh()),
        ], $vote->wasRecentlyCreated ? 201 : 200);
    }

    protected function resolveParticipant(Request $request): ?Participant
    {
        $token = $request->header('X-Participant-Token') ?: $request->input('token');

        if (! $token) {
            return null;
        }

        return Participant::with('table')->where('device_token', $token)->first();
    }
}
