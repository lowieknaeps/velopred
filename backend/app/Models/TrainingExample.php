<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingExample extends Model
{
    protected $fillable = [
        'race_id',
        'rider_id',
        'prediction_type',
        'stage_number',
        'model_version',
        'predicted_position',
        'actual_position',
        'actual_status',
        'race_slug',
        'race_year',
        'race_category',
        'race_type',
        'parcours_type',
        'stage_subtype',
        'features',
        'evaluated_at',
    ];

    protected $casts = [
        'features' => 'array',
        'evaluated_at' => 'datetime',
    ];

    public function race(): BelongsTo
    {
        return $this->belongsTo(Race::class);
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(Rider::class);
    }
}

