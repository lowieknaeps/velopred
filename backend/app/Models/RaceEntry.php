<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RaceEntry extends Model
{
    protected $fillable = ['race_id', 'rider_id', 'team_id', 'bib_number', 'startlist_order'];

    public function race(): BelongsTo   { return $this->belongsTo(Race::class); }
    public function rider(): BelongsTo  { return $this->belongsTo(Rider::class); }
    public function team(): BelongsTo   { return $this->belongsTo(Team::class); }
}
