<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Question extends Model
{
    public const MODE_INDIVIDUAL = 'individual';

    public const MODE_CONSENSUS = 'consensus';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'phase' => 'integer',
            'round' => 'integer',
            'duration' => 'integer',
            'is_bonus' => 'boolean',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(Option::class)->orderBy('order');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(TableVote::class);
    }

    public function participantVotes(): HasMany
    {
        return $this->hasMany(ParticipantVote::class);
    }

    public function isIndividual(): bool
    {
        return $this->mode === self::MODE_INDIVIDUAL;
    }

    /** A alternativa que a dinâmica considera a melhor decisão para o hotel. */
    public function bestOption(): ?Option
    {
        return $this->options->sortByDesc('points')->first();
    }
}
