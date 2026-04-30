<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiderResult extends Model
{
    protected $fillable = [
        'rider_id',
        'date',
        'position',
        'status',
        'race_name',
        'race_slug',
        'race_url',
        'race_class',
        'pcs_points',
        'uci_points',
        'season',
        'synced_at',
    ];

    protected $casts = [
        'date' => 'date',
        'synced_at' => 'datetime',
    ];

    public function rider(): BelongsTo
    {
        return $this->belongsTo(Rider::class);
    }
}

