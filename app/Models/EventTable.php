<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventTable extends Model
{
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
}
