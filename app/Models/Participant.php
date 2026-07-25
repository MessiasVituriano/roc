<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Participant extends Model
{
    protected $guarded = [];

    protected $hidden = ['device_token', 'email'];

    protected function casts(): array
    {
        return [
            'connected' => 'boolean',
            'last_seen' => 'datetime',
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
}
