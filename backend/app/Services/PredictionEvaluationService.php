<?php

namespace App\Services;

use App\Models\PredictionEvaluation;
use App\Models\Race;
use Illuminate\Support\Collection;

class PredictionEvaluationService
{
    public function evaluateRace(Race $race, string $predictionType = 'result', int $stageNumber = 0): ?PredictionEvaluation
    {
        $predictions = $race->predictions()
            ->where('prediction_type', $predictionType)
            ->where('stage_number', $stageNumber)
            ->orderBy('predicted_position')
            ->with('rider')
            ->get();

        $results = $race->results()
            ->where('result_type', $predictionType)
            ->when($predictionType === 'stage', fn ($query) => $query->where('stage_number', $stageNumber))
            ->whereNotNull('position')
            ->where('status', 'finished')
            ->orderBy('position')
            ->with('rider')
            ->get();

        if ($predictions->isEmpty() || $results->isEmpty()) {
            return null;
        }

        $predictedTop10 = $predictions->take(10)->values();
        $actualTop10 = $results->take(10)->values();
        $predictionsByRider = $predictions->keyBy('rider_id');
        $actualByRider = $results->keyBy('rider_id');

        $winner = $actualTop10->first();
        $predictedWinner = $predictedTop10->first();
        $winnerPrediction = $winner ? $predictionsByRider->get($winner->rider_id) : null;

        $top10Hits = $predictedTop10
            ->filter(fn ($prediction) => ($actualByRider->get($prediction->rider_id)?->position ?? 999) <= 10)
            ->count();

        $podiumHits = $predictedTop10
            ->take(3)
            ->filter(fn ($prediction) => ($actualByRider->get($prediction->rider_id)?->position ?? 999) <= 3)
            ->count();

        $exactPositionHits = $predictedTop10
            ->filter(fn ($prediction) => ($actualByRider->get($prediction->rider_id)?->position) === $prediction->predicted_position)
            ->count();

        $sharedTop10 = $actualTop10
            ->map(function ($result) use ($predictionsByRider) {
                $prediction = $predictionsByRider->get($result->rider_id);
                if (!$prediction) {
                    return null;
                }

                return [
                    'rider_slug' => $result->rider->pcs_slug,
                    'rider_name' => $result->rider->full_name,
                    'actual_position' => (int) $result->position,
                    'predicted_position' => (int) $prediction->predicted_position,
                    'absolute_error' => abs((int) $prediction->predicted_position - (int) $result->position),
                ];
            })
            ->filter()
            ->values();

        $mae = $sharedTop10->isNotEmpty()
            ? round((float) $sharedTop10->avg('absolute_error'), 4)
            : null;

        $metrics = [
            'race_slug' => $race->pcs_slug,
            'race_name' => $race->name,
            'winner' => $winner ? [
                'rider_slug' => $winner->rider->pcs_slug,
                'rider_name' => $winner->rider->full_name,
                'actual_position' => (int) $winner->position,
                'predicted_position' => $winnerPrediction?->predicted_position,
            ] : null,
            'predicted_winner' => $predictedWinner ? [
                'rider_slug' => $predictedWinner->rider->pcs_slug,
                'rider_name' => $predictedWinner->rider->full_name,
                'predicted_position' => (int) $predictedWinner->predicted_position,
                'actual_position' => $actualByRider->get($predictedWinner->rider_id)?->position,
            ] : null,
            'predicted_top10' => $this->formatPredictedTop10($predictedTop10, $actualByRider),
            'actual_top10' => $this->formatActualTop10($actualTop10, $predictionsByRider),
            'shared_top10' => $sharedTop10->all(),
        ];

        return PredictionEvaluation::updateOrCreate(
            [
                'race_id' => $race->id,
                'prediction_type' => $predictionType,
                'stage_number' => $stageNumber,
            ],
            [
                'winner_hit' => $winner !== null && $predictedWinner !== null && $winner->rider_id === $predictedWinner->rider_id,
                'winner_predicted_position' => $winnerPrediction?->predicted_position,
                'top10_hits' => $top10Hits,
                'podium_hits' => $podiumHits,
                'exact_position_hits' => $exactPositionHits,
                'shared_top10_riders' => $sharedTop10->count(),
                'top10_hit_rate' => $actualTop10->isNotEmpty() ? round($top10Hits / max($actualTop10->count(), 1), 4) : null,
                'mean_absolute_position_error' => $mae,
                'metrics' => $metrics,
                'evaluated_at' => now(),
            ]
        );
    }

    private function formatPredictedTop10(Collection $predictions, Collection $actualByRider): array
    {
        return $predictions->map(function ($prediction) use ($actualByRider) {
            return [
                'rider_slug' => $prediction->rider->pcs_slug,
                'rider_name' => $prediction->rider->full_name,
                'predicted_position' => (int) $prediction->predicted_position,
                'actual_position' => $actualByRider->get($prediction->rider_id)?->position,
                'win_probability' => (float) ($prediction->win_probability ?? 0),
                'raw_win_probability' => (float) ($prediction->raw_win_probability ?? $prediction->win_probability ?? 0),
                'top10_probability' => (float) ($prediction->top10_probability ?? 0),
                'raw_top10_probability' => (float) ($prediction->raw_top10_probability ?? $prediction->top10_probability ?? 0),
            ];
        })->all();
    }

    private function formatActualTop10(Collection $results, Collection $predictionsByRider): array
    {
        return $results->map(function ($result) use ($predictionsByRider) {
            $prediction = $predictionsByRider->get($result->rider_id);

            return [
                'rider_slug' => $result->rider->pcs_slug,
                'rider_name' => $result->rider->full_name,
                'actual_position' => (int) $result->position,
                'predicted_position' => $prediction?->predicted_position,
                'win_probability' => (float) ($prediction->win_probability ?? 0),
                'raw_win_probability' => (float) ($prediction->raw_win_probability ?? $prediction->win_probability ?? 0),
                'top10_probability' => (float) ($prediction->top10_probability ?? 0),
                'raw_top10_probability' => (float) ($prediction->raw_top10_probability ?? $prediction->top10_probability ?? 0),
            ];
        })->all();
    }
}
