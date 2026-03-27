<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PredictionEvaluation extends Model
{
    protected $fillable = [
        'race_id',
        'prediction_type',
        'stage_number',
        'winner_hit',
        'winner_predicted_position',
        'top10_hits',
        'podium_hits',
        'exact_position_hits',
        'shared_top10_riders',
        'top10_hit_rate',
        'mean_absolute_position_error',
        'metrics',
        'evaluated_at',
    ];

    protected $casts = [
        'winner_hit' => 'boolean',
        'metrics' => 'array',
        'evaluated_at' => 'datetime',
        'top10_hit_rate' => 'decimal:4',
        'mean_absolute_position_error' => 'decimal:4',
    ];

    public function race(): BelongsTo
    {
        return $this->belongsTo(Race::class);
    }
}
