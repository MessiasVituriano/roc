<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_OPEN = 'open';

    public const STATUS_RUNNING = 'running';

    public const STATUS_FINISHED = 'finished';

    /** Aguardando o facilitador abrir a rodada. */
    public const ROUND_IDLE = 'idle';

    /** Votação aberta. */
    public const ROUND_VOTING = 'voting';

    /** Consequência e placar no telão — sempre por clique do facilitador. */
    public const ROUND_REVEALED = 'revealed';

    public const LAST_PHASE = 2;

    protected $guarded = [];

    /** Os conjuntos de conteúdo cadastrados, para a tela de seleção agrupar. */
    public const SOURCES = [
        'roc-sp-2026' => 'ROC SP 2026',
        'roc-original' => 'Conjunto anterior',
    ];

    protected function casts(): array
    {
        return [
            'phase' => 'integer',
            'current_round' => 'integer',
            'round_duration' => 'integer',
            'responses_revealed' => 'boolean',
            'phase_one_revealed' => 'boolean',
            'answers_revealed' => 'boolean',
            'round_started_at' => 'datetime',
            'round_ends_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function tables(): HasMany
    {
        return $this->hasMany(EventTable::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }

    public function missions(): HasMany
    {
        return $this->hasMany(Mission::class);
    }

    public function currentQuestion(): ?Question
    {
        if (! $this->current_question_id) {
            return null;
        }

        return Question::with('options')->find($this->current_question_id);
    }

    /**
     * A pergunta de uma rodada. A rodada bônus de desempate fica fora do fluxo
     * normal — só entra pelo comando explícito do facilitador.
     */
    public function questionFor(int $phase, int $round, bool $includeBonus = false): ?Question
    {
        return $this->questions()
            ->with('options')
            ->where('phase', $phase)
            ->where('round', $round)
            // desligada não é alcançável pelo roteiro: ela continua no banco
            // (com os votos, se já foi jogada) mas some do caminho das rodadas
            ->where('active', true)
            ->when(! $includeBonus, fn ($q) => $q->where('is_bonus', false))
            ->first();
    }

    /**
     * A rodada final da Fase 2: a que não tem alternativas e é pontuada mesa a
     * mesa pelo facilitador.
     */
    public function finalQuestion(): ?Question
    {
        return $this->questions()
            ->where('manual_scoring', true)
            ->where('active', true)
            ->orderBy('phase')
            ->orderBy('round')
            ->first();
    }

    public function roundsInPhase(?int $phase = null): int
    {
        return $this->questions()
            ->where('phase', $phase ?? $this->phase)
            ->where('is_bonus', false)
            ->where('active', true)
            ->count();
    }

    public function isIndividualPhase(): bool
    {
        return $this->phase === 1;
    }

    /**
     * Segundos restantes da rodada. Sempre calculado no servidor, para que
     * celular, telão e painel compartilhem o mesmo relógio.
     */
    public function remainingSeconds(): int
    {
        if ($this->round_status !== self::ROUND_VOTING || ! $this->round_ends_at) {
            return 0;
        }

        return max(0, (int) ceil(now()->diffInSeconds($this->round_ends_at, false)));
    }

    public function isAcceptingVotes(): bool
    {
        return $this->round_status === self::ROUND_VOTING && $this->remainingSeconds() > 0;
    }
}
