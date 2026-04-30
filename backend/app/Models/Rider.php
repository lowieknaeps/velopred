<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Rider extends Model
{
    protected $fillable = [
        'pcs_slug',
        'first_name',
        'last_name',
        'nationality',
        'date_of_birth',
        'team_id',
        'uci_ranking',
        'pcs_ranking',
        'career_points',
        'pcs_speciality_one_day',
        'pcs_speciality_gc',
        'pcs_speciality_tt',
        'pcs_speciality_sprint',
        'pcs_speciality_climber',
        'pcs_speciality_hills',
        'pcs_weight_kg',
        'pcs_height_m',
        'age_approx',
        'synced_at',
        'profile_synced_at',
        'results_synced_at',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'pcs_weight_kg' => 'float',
        'pcs_height_m'  => 'float',
        'synced_at'     => 'datetime',
        'profile_synced_at' => 'datetime',
        'results_synced_at' => 'datetime',
    ];

    // ── Relaties ──────────────────────────────────────────────────────────────

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function raceResults(): HasMany
    {
        return $this->hasMany(RaceResult::class);
    }

    public function riderResults(): HasMany
    {
        return $this->hasMany(RiderResult::class);
    }

    public function predictions(): HasMany
    {
        return $this->hasMany(Prediction::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    public function getAgeAttribute(): ?int
    {
        return $this->date_of_birth?->age;
    }

    public function watchlistedBy(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'watchlists')->withTimestamps();
    }
}
