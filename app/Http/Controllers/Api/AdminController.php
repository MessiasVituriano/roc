<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventTable;
use App\Models\Participant;
use App\Models\ParticipantVote;
use App\Models\Question;
use App\Models\TableVote;
use App\Services\EventFlowService;
use App\Services\EventStateService;
use Database\Seeders\LiveConsensusSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;

class AdminController extends Controller
{
    public function __construct(
        protected EventStateService $state,
        protected EventFlowService $flow,
    ) {}

    /** Polled once per second by the master panel — o mapa mais as 3 camadas de placar. */
    public function overview(): JsonResponse
    {
        return response()->json($this->state->display($this->requireEvent(), forMaster: true));
    }

    public function open(): JsonResponse
    {
        return $this->respond($this->flow->open($this->requireEvent()));
    }

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'duration' => ['nullable', 'integer', 'min:5', 'max:600'],
        ]);

        try {
            return $this->respond($this->flow->startRound($this->requireEvent(), $data['duration'] ?? null));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    /** Fecha a votação sem revelar — o telão fica na pergunta. */
    public function close(): JsonResponse
    {
        return $this->respond($this->flow->closeRound($this->requireEvent()));
    }

    /** O clique que leva consequência e pontos ao telão. */
    public function reveal(): JsonResponse
    {
        return $this->respond($this->flow->reveal($this->requireEvent()));
    }

    public function unreveal(): JsonResponse
    {
        return $this->respond($this->flow->unreveal($this->requireEvent()));
    }

    public function next(): JsonResponse
    {
        return $this->respond($this->flow->nextRound($this->requireEvent()));
    }

    /** Volta uma rodada — e, na primeira da fase, volta a fase inteira. */
    public function previous(): JsonResponse
    {
        return $this->respond($this->flow->previousRound($this->requireEvent()));
    }

    /** Os +10s / +30s do painel: estica a rodada sem reiniciar o cronômetro. */
    public function addTime(Request $request): JsonResponse
    {
        $data = $request->validate([
            'seconds' => ['required', 'integer', 'min:1', 'max:600'],
        ]);

        return $this->respond($this->flow->addTime($this->requireEvent(), $data['seconds']));
    }

    /** A virada de fase: placar por grupo de missão no telão. */
    public function revealMissions(): JsonResponse
    {
        return $this->respond($this->flow->revealMissions($this->requireEvent()));
    }

    public function hideMissions(): JsonResponse
    {
        return $this->respond($this->flow->hideMissions($this->requireEvent()));
    }

    /** O fecho: gabarito e comparativo entre as fases vão ao telão. */
    public function revealAnswers(): JsonResponse
    {
        return $this->respond($this->flow->revealAnswers($this->requireEvent()));
    }

    public function hideAnswers(): JsonResponse
    {
        return $this->respond($this->flow->hideAnswers($this->requireEvent()));
    }

    public function nextPhase(): JsonResponse
    {
        return $this->respond($this->flow->nextPhase($this->requireEvent()));
    }

    public function end(): JsonResponse
    {
        return $this->respond($this->flow->end($this->requireEvent()));
    }

    /** Carrega a pergunta bônus (4º critério de desempate). */
    public function bonusRound(Request $request): JsonResponse
    {
        $data = $request->validate(['which' => ['nullable', 'integer', 'in:1,2']]);

        try {
            return $this->respond($this->flow->loadBonusRound(
                $this->requireEvent(),
                (int) ($data['which'] ?? 1),
            ));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    /**
     * Fecha a Fase 1 devolvendo a cada pessoa o próprio total — e só ele.
     * O gabarito continua atrás do clique do fecho.
     */
    public function revealPhaseOne(): JsonResponse
    {
        return $this->respond($this->flow->revealPhaseOne($this->requireEvent()));
    }

    public function hidePhaseOne(): JsonResponse
    {
        return $this->respond($this->flow->hidePhaseOne($this->requireEvent()));
    }

    /**
     * O acervo de perguntas, para a tela de seleção.
     *
     * Um cenário é **um par**: a mesma pergunta na Fase 1 e na Fase 2. Elas
     * ligam e desligam juntas — o comparativo do fecho compara a mesma pergunta
     * dos dois lados, e deixar um lado ativo sem o outro o quebraria em
     * silêncio. Por isso a lista é de cenários, não de perguntas.
     */
    public function questionCatalog(): JsonResponse
    {
        $event = $this->requireEvent();

        $scenarios = $event->questions()
            ->with('options')
            ->whereNotNull('scenario_key')
            ->where('phase', 1)
            ->orderBy('round')
            ->get()
            ->map(fn (Question $q) => [
                'scenario_key' => $q->scenario_key,
                'source' => $q->source,
                'label' => $q->label,
                'title' => $q->title,
                'context' => $q->context,
                'active' => (bool) $q->active,
                'round' => $q->round,
                'options' => $q->options->sortByDesc('points')->pluck('text')->values(),
            ]);

        $bonus = $event->questions()
            ->with('options')
            ->where('is_bonus', true)
            ->orderBy('round')
            ->get()
            ->map(fn (Question $q) => [
                'id' => $q->id,
                'source' => $q->source,
                'label' => $q->label,
                'title' => $q->title,
                'context' => $q->context,
                'active' => (bool) $q->active,
            ]);

        return response()->json([
            'sources' => Event::SOURCES,
            'scenarios' => $scenarios,
            'tie_breaks' => $bonus,
        ]);
    }

    /**
     * Troca a seleção: quais cenários e quais desempates o evento joga.
     *
     * Renumera na saída, para as rodadas ativas voltarem contíguas — é por
     * `round`, um número de cada vez, que o evento anda.
     */
    public function selectQuestions(Request $request): JsonResponse
    {
        $event = $this->requireEvent();

        $data = $request->validate([
            'scenarios' => ['present', 'array'],
            'scenarios.*' => ['string'],
            'tie_breaks' => ['present', 'array'],
            'tie_breaks.*' => ['integer'],
        ]);

        abort_if(
            $data['scenarios'] === [],
            422,
            'Selecione pelo menos um cenário — sem nenhum não há evento a conduzir.',
        );

        $event->questions()->whereNotNull('scenario_key')->update(['active' => false]);
        $event->questions()->whereNotNull('scenario_key')
            ->whereIn('scenario_key', $data['scenarios'])->update(['active' => true]);

        $event->questions()->where('is_bonus', true)->update(['active' => false]);
        $event->questions()->where('is_bonus', true)
            ->whereIn('id', $data['tie_breaks'])->update(['active' => true]);

        $this->flow->renumberRounds($event);

        // a rodada corrente pode ter saído da seleção: o evento volta ao começo
        // da fase em que está, que é o único ponto seguro
        $event->refresh();

        if (! $event->questionFor($event->phase, $event->current_round)) {
            $first = $event->questionFor($event->phase, 1);
            $event->update([
                'current_round' => 1,
                'current_question_id' => $first?->id,
                'round_status' => Event::ROUND_IDLE,
                'round_started_at' => null,
                'round_ends_at' => null,
            ]);
        }

        return $this->respond($event->refresh());
    }

    /**
     * A pontuação da rodada final, lançada mesa a mesa.
     *
     * A rodada final não tem alternativas — a mesa decide livremente e quem
     * pontua é o facilitador. A linha entra em `table_votes` sem `option_id`,
     * então soma no placar da Fase 2 como qualquer outra decisão de mesa, mas
     * nunca conta como acerto: não havia régua para acertar.
     *
     * `points: null` apaga o lançamento, para corrigir um valor digitado errado.
     */
    public function finalScore(Request $request): JsonResponse
    {
        $event = $this->requireEvent();
        $question = $event->finalQuestion();

        abort_if(! $question, 409, 'Este evento não tem rodada final cadastrada.');

        $data = $request->validate([
            'event_table_id' => ['required', Rule::exists('event_tables', 'id')->where('event_id', $event->id)],
            'points' => ['present', 'nullable', 'integer', 'min:-1000', 'max:1000'],
        ]);

        $target = TableVote::where('question_id', $question->id)
            ->where('event_table_id', $data['event_table_id']);

        if ($data['points'] === null) {
            $target->delete();
        } else {
            TableVote::updateOrCreate(
                [
                    'question_id' => $question->id,
                    'event_table_id' => $data['event_table_id'],
                ],
                [
                    'option_id' => null,
                    'participant_id' => null,
                    'points' => $data['points'],
                ],
            );
        }

        return $this->respond($event->refresh());
    }

    /** Sorteia (ou re-sorteia) as missões da Fase 1. */
    public function assignMissions(Request $request): JsonResponse
    {
        $data = $request->validate(['reassign' => ['nullable', 'boolean']]);

        try {
            $count = $this->flow->assignMissions($this->requireEvent(), (bool) ($data['reassign'] ?? false));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(
            ['assigned' => $count] + $this->state->display($this->requireEvent(), forMaster: true)
        );
    }

    /** Detail drawer in the master panel when a table is clicked. */
    public function table(EventTable $table): JsonResponse
    {
        $event = $this->requireEvent();
        $question = $event->currentQuestion();

        $votes = $question
            ? ParticipantVote::where('question_id', $question->id)
                ->where('event_table_id', $table->id)
                ->pluck('participant_id')
            : collect();

        $totals = ParticipantVote::query()
            ->join('questions', 'questions.id', '=', 'participant_votes.question_id')
            ->where('questions.event_id', $event->id)
            ->where('questions.phase', 1)
            ->where('participant_votes.event_table_id', $table->id)
            ->selectRaw('participant_votes.participant_id, sum(participant_votes.points) as total')
            ->groupBy('participant_votes.participant_id')
            ->pluck('total', 'participant_id');

        return response()->json([
            'id' => $table->id,
            'name' => $table->name,
            'icon' => $table->icon,
            'color' => $table->color,
            'representative_id' => $table->representative_id,
            'participants' => $table->participants()
                ->with('mission')
                ->orderBy('id')
                ->get()
                ->map(fn (Participant $p) => $this->state->participant($p) + [
                    'last_seen' => $p->last_seen?->toIso8601String(),
                    'voted' => $votes->contains($p->id),
                    'points' => (int) ($totals[$p->id] ?? 0),
                    'mission' => $p->mission?->name,
                    'mission_color' => $p->mission?->color,
                    'is_representative' => $table->representative_id === $p->id,
                    'blocked' => $p->isBlocked(),
                    // o contato e o hotel só existem aqui: é a gaveta que o
                    // facilitador abre para identificar quem é quem antes de
                    // bloquear, e ela nunca é projetada
                    'contact' => $p->contact(),
                    'hotel' => $p->hotel,
                ])
                ->all(),
        ]);
    }

    /**
     * Bloqueia (ou libera) uma pessoa no ranking individual.
     *
     * O bloqueio é de vitrine, não de participação: os votos continuam
     * contando para a mesa e para o grupo de missão, e a pessoa segue votando
     * pelo celular sem ver diferença alguma. O que muda é que ela sai do
     * ranking individual — o único placar que vai ao telão com nome e avatar.
     *
     * É a saída para o nome impróprio, o cadastro duplicado e quem está na sala
     * ajudando a conduzir: apagar o voto seria mexer no critério de vitória da
     * mesa por causa de um problema que é só de exibição.
     */
    public function blockParticipant(Request $request, Participant $participant): JsonResponse
    {
        $data = $request->validate(['blocked' => ['required', 'boolean']]);

        $participant->forceFill([
            'blocked_at' => $data['blocked'] ? now() : null,
        ])->save();

        // representante bloqueado é contradição: quem não pode ser eleito
        // também não pode continuar no posto. A mesa volta a ficar livre e o
        // primeiro colega que tocar no botão assume.
        if ($data['blocked']) {
            EventTable::where('representative_id', $participant->id)
                ->update(['representative_id' => null]);
        }

        return $this->respond($this->requireEvent());
    }

    /**
     * The master can appoint or swap a table's representative for phase 2.
     *
     * Bloqueado fica fora da lista elegível — o `whereNull` no `exists` recusa
     * pelo mesmo caminho de um id de outra mesa.
     */
    public function setRepresentative(Request $request, EventTable $table): JsonResponse
    {
        $data = $request->validate([
            'participant_id' => ['nullable', Rule::exists('participants', 'id')
                ->where('event_table_id', $table->id)
                ->whereNull('blocked_at')],
        ], [
            'participant_id.exists' => 'Esta pessoa não pode representar a mesa.',
        ]);

        $table->update(['representative_id' => $data['participant_id'] ?? null]);

        return $this->respond($this->requireEvent());
    }

    public function storeTable(Request $request): JsonResponse
    {
        $event = $this->requireEvent();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:40'],
            'icon' => ['nullable', 'string', 'max:8'],
            'color' => ['nullable', 'string', 'max:9'],
            'position_x' => ['nullable', 'numeric', 'between:0,100'],
            'position_y' => ['nullable', 'numeric', 'between:0,100'],
        ]);

        $table = $event->tables()->create($data + ['icon' => $data['icon'] ?? '🚀']);

        return response()->json($table, 201);
    }

    public function updateTable(Request $request, EventTable $table): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:40'],
            'icon' => ['sometimes', 'string', 'max:8'],
            'color' => ['sometimes', 'string', 'max:9'],
            'position_x' => ['sometimes', 'numeric', 'between:0,100'],
            'position_y' => ['sometimes', 'numeric', 'between:0,100'],
        ]);

        $table->update($data);

        return response()->json($table);
    }

    public function destroyTable(EventTable $table): JsonResponse
    {
        $table->delete();

        return response()->json(['deleted' => true]);
    }

    /** Bulk save of the drag-and-drop auditorium layout. */
    public function saveLayout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tables' => ['required', 'array'],
            'tables.*.id' => ['required', Rule::exists('event_tables', 'id')],
            'tables.*.position_x' => ['required', 'numeric', 'between:0,100'],
            'tables.*.position_y' => ['required', 'numeric', 'between:0,100'],
        ]);

        foreach ($data['tables'] as $row) {
            EventTable::where('id', $row['id'])->update([
                'position_x' => $row['position_x'],
                'position_y' => $row['position_y'],
            ]);
        }

        return response()->json(['saved' => count($data['tables'])]);
    }

    /** Questions of the whole event, so the panel can show what is coming next. */
    public function questions(): JsonResponse
    {
        $event = $this->requireEvent();

        return response()->json(
            $event->questions()
                ->with('options')
                ->orderBy('phase')
                ->orderBy('round')
                ->get()
                ->map(fn ($q) => $this->state->question($q, withAnswerKey: true, forMaster: true))
        );
    }

    /**
     * Recarrega a rodada atual: zera os votos dela e volta ao ponto de abrir a
     * votação. Saída de emergência durante o evento.
     */
    public function resetRound(): JsonResponse
    {
        return $this->respond($this->flow->resetRound($this->requireEvent()));
    }

    /**
     * Recomeça do zero: apaga o evento inteiro (as FKs cascateiam para mesas,
     * participantes e votos) e re-semeia um evento novo em rascunho. É o "abrir
     * de novo" quando o evento anterior já foi encerrado.
     */
    public function resetEvent(): JsonResponse
    {
        DB::transaction(fn () => Event::query()->delete());

        Artisan::call('db:seed', [
            '--class' => LiveConsensusSeeder::class,
            '--force' => true,
        ]);

        return $this->respond($this->requireEvent());
    }

    protected function requireEvent(): Event
    {
        $event = $this->state->activeEvent();

        abort_if(! $event, 404, 'Nenhum evento cadastrado.');

        return $event;
    }

    protected function respond(Event $event): JsonResponse
    {
        return response()->json($this->state->display($event, forMaster: true));
    }
}
