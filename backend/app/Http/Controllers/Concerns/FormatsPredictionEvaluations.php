<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Race;

trait FormatsPredictionEvaluations
{
    private function formatEvaluationPayload(Race $race, array $context): ?array
    {
        $evaluation = $race->predictionEvaluations()
            ->where('prediction_type', $context['prediction_type'])
            ->where('stage_number', (int) ($context['stage_number'] ?? 0))
            ->latest('evaluated_at')
            ->first();

        if (!$evaluation) {
            return null;
        }

        $metrics = $evaluation->metrics ?? [];

        return [
            'top10_hits' => $evaluation->top10_hits,
            'top10_hit_rate' => $evaluation->top10_hit_rate,
            'exact_position_hits' => $evaluation->exact_position_hits,
            'podium_hits' => $evaluation->podium_hits,
            'mean_absolute_position_error' => $evaluation->mean_absolute_position_error,
            'evaluated_at' => $evaluation->evaluated_at?->locale('nl_BE')->translatedFormat('d MMM Y HH:mm'),
            'shared_top10' => $metrics['shared_top10'] ?? [],
            'predicted_top10' => $metrics['predicted_top10'] ?? [],
            'actual_top10' => $metrics['actual_top10'] ?? [],
        ];
    }
}
