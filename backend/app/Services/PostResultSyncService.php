<?php

namespace App\Services;

use App\Models\Race;
use App\Models\TrainingExample;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PostResultSyncService
{
    public function __construct(
        private PredictionEvaluationService $evaluationService,
        private UpcomingOneDayPredictionRefreshService $predictionRefreshService,
        private TrainingDatasetService $trainingDataset,
        private ExternalCyclingApiService $api,
        private PredictionService $predictionService,
    ) {}

    public function handle(Race $race, bool $refreshUpcomingPredictions = true): array
    {
        $race->refresh();

        // Persist training examples whenever we have both predictions and results for a context.
        // This is useful for both one-day races and stage races.
        $savedExamples = $this->trainingDataset->persistAllAvailableContexts($race);

        // Stage races: learn after each newly available stage result so next stages improve.
        $stageLearning = $this->runStageRaceLearningLoop($race, $savedExamples);

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
                'stage_learning' => $stageLearning,
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
            'stage_learning' => $stageLearning,
        ];
    }

    private function runStageRaceLearningLoop(Race $race, int $savedExamples): array
    {
        if (!$race->isStageRace()) {
            return ['ran' => false];
        }

        $latestFinishedStage = (int) $race->results()
            ->where('result_type', 'stage')
            ->whereNotNull('position')
            ->where('status', 'finished')
            ->max('stage_number');

        if ($latestFinishedStage <= 0) {
            return ['ran' => false];
        }

        $cacheKey = "stage-learning:race:{$race->id}:stage:{$latestFinishedStage}";
        if (Cache::has($cacheKey)) {
            return ['ran' => false, 'reason' => 'already_processed'];
        }

        // Keep training focused and fast: recent, relevant contexts only.
        $rows = TrainingExample::query()
            ->whereIn('prediction_type', ['stage', 'gc', 'points', 'kom', 'youth'])
            ->where('race_year', '>=', now()->year - 1)
            ->orderByDesc('evaluated_at')
            ->limit(5000)
            ->get();

        if ($rows->isEmpty()) {
            return ['ran' => false, 'reason' => 'no_examples'];
        }

        $examples = $rows->map(function (TrainingExample $ex) {
            $features = is_array($ex->features) ? $ex->features : (json_decode((string) $ex->features, true) ?: []);

            return array_merge($features, [
                'actual_position' => $ex->actual_position,
                'actual_status' => $ex->actual_status,
                'race_year' => (int) $ex->race_year,
                'parcours_type' => (string) ($ex->parcours_type ?? 'default'),
                'category_weight' => 1.0,
            ]);
        })->all();

        try {
            $trainStats = $this->api->trainFromExamples($examples);
            $this->predictionService->predictRace($race->fresh());

            Cache::put($cacheKey, now()->toIso8601String(), now()->addDays(14));

            Log::info("[StageLearning] {$race->name} stage {$latestFinishedStage}: trained from " . count($examples) . " examples, model=" . ($trainStats['model_version'] ?? 'unknown'));

            return [
                'ran' => true,
                'examples' => count($examples),
                'saved_examples' => $savedExamples,
                'model_version' => $trainStats['model_version'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::warning("[StageLearning] {$race->name} stage {$latestFinishedStage} failed: {$e->getMessage()}");
            return ['ran' => false, 'reason' => 'training_failed'];
        }
    }
}
