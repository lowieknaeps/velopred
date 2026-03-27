<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Prediction extends Model
{
    protected $fillable = [
        'race_id',
        'rider_id',
        'prediction_type',
        'stage_number',
        'predicted_position',
        'top10_probability',
        'raw_top10_probability',
        'win_probability',
        'raw_win_probability',
        'confidence_score',
        'features',
        'model_version',
    ];

    protected $casts = [
        'stage_number'       => 'integer',
        'features'           => 'array',
        'top10_probability'  => 'decimal:4',
        'raw_top10_probability' => 'decimal:4',
        'win_probability'    => 'decimal:4',
        'raw_win_probability' => 'decimal:4',
        'confidence_score'   => 'decimal:4',
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
