<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TableVote extends Model
{
    protected $guarded = [];

    public function table(): BelongsTo
    {
        return $this->belongsTo(EventTable::class, 'event_table_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(Option::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }
}
