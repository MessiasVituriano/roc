<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A missão situacional sorteada para cada pessoa na Fase 1. É ela — e não o
 * cargo real — que cria o viés revelado na virada de fase.
 */
class Mission extends Model
{
    protected $guarded = [];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class);
    }
}
