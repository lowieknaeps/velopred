<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends Model
{
    protected $fillable = [
        'pcs_slug',
        'name',
        'nationality',
        'category',
        'synced_at',
    ];

    protected $casts = [
        'synced_at' => 'datetime',
    ];

    public function riders(): HasMany
    {
        return $this->hasMany(Rider::class);
    }

    public function raceResults(): HasMany
    {
        return $this->hasMany(RaceResult::class);
    }
}
