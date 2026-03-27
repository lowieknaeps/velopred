<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Race extends Model
{
    protected $fillable = [
        'pcs_slug',
        'name',
        'year',
        'start_date',
        'end_date',
        'country',
        'category',
        'race_type',
        'parcours_type',
        'stages_json',
        'synced_at',
        'startlist_synced_at',
    ];

    protected $casts = [
        'start_date'         => 'date',
        'end_date'           => 'date',
        'stages_json'        => 'array',
        'synced_at'          => 'datetime',
        'startlist_synced_at'=> 'datetime',
    ];

    // ── Relaties ──────────────────────────────────────────────────────────────

    public function results(): HasMany
    {
        return $this->hasMany(RaceResult::class);
    }

    public function predictions(): HasMany
    {
        return $this->hasMany(Prediction::class);
    }

    public function predictionEvaluations(): HasMany
    {
        return $this->hasMany(PredictionEvaluation::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(RaceEntry::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeOneDay($query)
    {
        return $query->where('race_type', 'one_day');
    }

    public function scopeStageRace($query)
    {
        return $query->where('race_type', 'stage_race');
    }

    /**
     * Enkel relevante mannenelite races tonen.
     * Filtert vrouwen-, junior- en beloftenwedstrijden weg.
     */
    public function scopeRelevant($query)
    {
        return $query->where(function ($q) {
            $q->whereNotIn('category', ['Woman Elite'])
              ->where('name', 'NOT LIKE', '% WE - %')
              ->where('name', 'NOT LIKE', '% WJ - %')
              ->where('name', 'NOT LIKE', '% WU - %')
              ->where('name', 'NOT LIKE', '% MJ - %')
              ->where('name', 'NOT LIKE', '% MU - %')
              ->where('name', 'NOT LIKE', '%Women%')
              ->where('name', 'NOT LIKE', '%Mixed Relay%');
        });
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isOneDay(): bool
    {
        return $this->race_type === 'one_day';
    }

    public function isStageRace(): bool
    {
        return $this->race_type === 'stage_race';
    }

    public function hasStarted(?CarbonInterface $moment = null): bool
    {
        $momentDate = ($moment ?? now())->toDateString();

        return $this->start_date !== null
            && $this->start_date->toDateString() <= $momentDate;
    }

    public function hasFinished(?CarbonInterface $moment = null): bool
    {
        $momentDate = ($moment ?? now())->toDateString();
        $endDate = $this->end_date?->toDateString() ?? $this->start_date?->toDateString();

        return $endDate !== null && $endDate < $momentDate;
    }

    public function isLive(?CarbonInterface $moment = null): bool
    {
        return $this->hasStarted($moment) && !$this->hasFinished($moment);
    }
}
