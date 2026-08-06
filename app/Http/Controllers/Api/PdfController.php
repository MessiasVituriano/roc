<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Participant;
use App\Models\ParticipantVote;
use App\Models\Question;
use App\Services\EventStateService;
use App\Services\ScoreService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Os dois papéis que o evento produz.
 *
 * O do facilitador é a lista de contatos — o motivo pelo qual o cadastro pede
 * e-mail ou telefone. O do participante são as próprias decisões com as
 * justificativas de cada alternativa, que é o que faz a conversa continuar
 * depois que a sala esvazia.
 *
 * Os dois saem em PDF de verdade, gerado no servidor: o material vai para o
 * WhatsApp e para o e-mail de gente que abre em aparelho qualquer, e "imprima
 * esta página" não sobrevive a esse caminho.
 */
class PdfController extends Controller
{
    public function __construct(
        protected EventStateService $state,
        protected ScoreService $scores,
    ) {}

    /**
     * A lista de contatos, atrás do token de master.
     *
     * É o único lugar em que o contato de todo mundo sai junto — por isso não
     * há versão pública dele, nem link no telão.
     */
    public function contacts(): Response
    {
        $event = $this->state->activeEvent();

        abort_if(! $event, 404, 'Nenhum evento disponível.');

        $people = $event->participants()
            ->with('table')
            ->orderBy('name')
            ->get();

        $pdf = Pdf::loadView('pdf.contacts', [
            'event' => $event,
            'people' => $people,
            'generatedAt' => now()->format('d/m/Y H:i'),
        ]);

        return $pdf->download($this->fileName($event, 'contatos'));
    }

    /**
     * As decisões da própria pessoa, com as justificativas.
     *
     * Só depois de o gabarito ser aberto: antes disso este arquivo seria a
     * régua do evento em PDF, baixável por qualquer celular no meio da sala.
     */
    public function myAnswers(Request $request): Response
    {
        $event = $this->state->activeEvent();

        abort_if(! $event, 404, 'Nenhum evento disponível.');

        $participant = $this->resolve($request, $event);

        abort_if(! $participant, 401, 'Participante não identificado.');
        abort_if(
            ! $this->state->showsAnswerKey($event),
            409,
            'O gabarito ainda não foi aberto pelo facilitador.',
        );

        $questions = $event->questions()
            ->with('options')
            ->where('phase', 1)
            ->where('is_bonus', false)
            ->orderBy('round')
            ->get();

        $mine = ParticipantVote::where('participant_id', $participant->id)
            ->whereIn('question_id', $questions->pluck('id'))
            ->pluck('option_id', 'question_id');

        $rounds = $questions->map(function (Question $question) use ($mine) {
            $best = $question->bestOption();
            $chosen = $mine[$question->id] ?? null;

            return [
                'round' => $question->round,
                'label' => $question->label,
                'title' => $question->title,
                'context' => $question->context,
                'answered' => $chosen !== null,
                'options' => $question->options
                    ->sortByDesc('points')
                    ->map(fn ($option) => [
                        'text' => $option->text,
                        'effect' => $option->effect,
                        'points' => $option->points,
                        'is_best' => $best !== null && $option->id === $best->id,
                        'is_mine' => $chosen !== null && $option->id === $chosen,
                    ])
                    ->values()
                    ->all(),
            ];
        });

        $pdf = Pdf::loadView('pdf.answers', [
            'event' => $event,
            'participant' => $participant->load('table'),
            'rounds' => $rounds,
            'totalPoints' => $this->scores->participantPoints($event, $participant),
            'correct' => $this->scores->participantCorrect($event, $participant),
            'generatedAt' => now()->format('d/m/Y H:i'),
        ]);

        return $pdf->download($this->fileName($event, Str::slug($participant->name)));
    }

    protected function resolve(Request $request, Event $event): ?Participant
    {
        $token = $request->header('X-Participant-Token') ?: $request->query('token');

        return $token
            ? Participant::where('event_id', $event->id)->where('device_token', $token)->first()
            : null;
    }

    protected function fileName(Event $event, string $suffix): string
    {
        return Str::slug($event->title)."-{$suffix}.pdf";
    }
}
