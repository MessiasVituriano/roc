<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Participant extends Model
{
    protected $guarded = [];

    // dado de contato não vaza por serialização acidental: as telas públicas
    // montam os payloads campo a campo, e nenhuma delas pede contato
    protected $hidden = ['device_token', 'email', 'phone'];

    protected function casts(): array
    {
        return [
            'connected' => 'boolean',
            'last_seen' => 'datetime',
            'blocked_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(EventTable::class, 'event_table_id');
    }

    public function mission(): BelongsTo
    {
        return $this->belongsTo(Mission::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(ParticipantVote::class);
    }

    /**
     * A participant counts as online while their polling keeps touching last_seen.
     */
    public function isOnline(): bool
    {
        return $this->last_seen !== null && $this->last_seen->gt(now()->subSeconds(15));
    }

    /**
     * Bloqueado pelo facilitador: sai do ranking individual e continua na
     * dinâmica. Os votos seguem contando para a mesa e para o grupo de missão —
     * o bloqueio é sobre aparecer no pódio, não sobre participar.
     */
    public function isBlocked(): bool
    {
        return $this->blocked_at !== null;
    }

    /** O contato que a pessoa deixou: e-mail ou telefone, um dos dois. */
    public function contact(): ?string
    {
        return $this->email ?: $this->phone;
    }
}
