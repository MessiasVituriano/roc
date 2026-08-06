<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventTable extends Model
{
    /**
     * Teto de gente por mesa.
     *
     * Dez é o tamanho que o rodízio de missões já pressupõe: quatro missões
     * diferentes nos quatro primeiros, o ciclo repetido nos quatro seguintes e
     * uma sobra de dois. E é o teto da conversa — a Fase 2 se decide por
     * consenso em dois minutos, e consenso de doze em dois minutos não
     * acontece: vira a opinião dos dois mais falantes.
     */
    public const MAX_PARTICIPANTS = 10;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'position_x' => 'float',
            'position_y' => 'float',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(TableVote::class);
    }

    /** Phase 2: the single person who answers for this table. */
    public function representative(): BelongsTo
    {
        return $this->belongsTo(Participant::class, 'representative_id');
    }

    /** Sem cadeira livre. Só é confiável dentro da transação que trava a linha. */
    public function isFull(): bool
    {
        return $this->participants()->count() >= self::MAX_PARTICIPANTS;
    }
}
