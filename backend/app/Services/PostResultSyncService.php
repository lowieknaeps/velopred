<?php

namespace App\Services;

use App\Models\Race;
use Illuminate\Support\Facades\Log;

class PostResultSyncService
{
    public function __construct(
        private PredictionEvaluationService $evaluationService,
        private UpcomingOneDayPredictionRefreshService $predictionRefreshService,
    ) {}

    public function handle(Race $race, bool $refreshUpcomingPredictions = true): array
    {
        $race->refresh();

        if (!$race->isOneDay()) {
            return [
                'evaluated' => false,
                'refreshed_upcoming_predictions' => 0,
            ];
        }

        $hasFinalResults = $race->results()
            ->where('result_type', 'result')
            ->whereNull('stage_number')
            ->whereNotNull('position')
            ->where('status', 'finished')
            ->exists();

        if (!$hasFinalResults) {
            return [
                'evaluated' => false,
                'refreshed_upcoming_predictions' => 0,
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
        ];
    }
}
