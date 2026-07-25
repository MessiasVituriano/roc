<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParticipantVote extends Model
{
    protected $guarded = [];

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function mission(): BelongsTo
    {
        return $this->belongsTo(Mission::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(Option::class);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(EventTable::class, 'event_table_id');
    }
}
