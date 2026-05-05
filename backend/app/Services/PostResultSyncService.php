<?php

namespace App\Services;

use App\Models\Race;
use Illuminate\Support\Facades\Log;

class PostResultSyncService
{
    public function __construct(
        private PredictionEvaluationService $evaluationService,
        private UpcomingOneDayPredictionRefreshService $predictionRefreshService,
        private TrainingDatasetService $trainingDataset,
    ) {}

    public function handle(Race $race, bool $refreshUpcomingPredictions = true): array
    {
        $race->refresh();

        // Persist training examples whenever we have both predictions and results for a context.
        // This is useful for both one-day races and stage races.
        $savedExamples = $this->trainingDataset->persistAllAvailableContexts($race);

        $hasFinalResults = $race->results()
            ->where('result_type', 'result')
            ->whereNull('stage_number')
            ->whereNotNull('position')
            ->where('status', 'finished')
            ->exists();

        if (!$race->isOneDay() || !$hasFinalResults) {
            return [
                'evaluated' => false,
                'refreshed_upcoming_predictions' => 0,
                'saved_training_examples' => $savedExamples,
            ];
        }

        $evaluation = $this->evaluationService->evaluateRace($race, 'result', 0);
        $refreshed = 0;

        if ($refreshUpcomingPredictions && $evaluation !== null) {
            $refreshed = $this->predictionRefreshService->refresh();
        }

        if ($evaluation !== null) {
            Log::info("[PostResultSync] {$race->name}: top10_hits={$evaluation->top10_hits}, exact_hits={$evaluation->exact_position_hits}, refreshed={$refreshed}");
        }

        return [
            'evaluated' => $evaluation !== null,
            'refreshed_upcoming_predictions' => $refreshed,
            'saved_training_examples' => $savedExamples,
        ];
    }
}
