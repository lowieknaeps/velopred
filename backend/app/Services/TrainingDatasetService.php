<?php

namespace App\Services;

use App\Models\Race;
use App\Models\RaceResult;
use App\Models\TrainingExample;
use Illuminate\Support\Collection;

class TrainingDatasetService
{
    /**
     * Persist per-rider training examples for a given context.
     * Includes finished + non-finished statuses, so DNF/DNS doesn't look like "bad form".
     */
    public function persistRaceContext(Race $race, string $predictionType, int $stageNumber = 0): int
    {
        $predictions = $race->predictions()
            ->where('prediction_type', $predictionType)
            ->where('stage_number', $stageNumber)
            ->with('rider')
            ->get();

        $results = $race->results()
            ->where('result_type', $predictionType)
            ->when($predictionType === 'stage', fn ($q) => $q->where('stage_number', $stageNumber))
            ->with('rider')
            ->get();

        if ($predictions->isEmpty() || $results->isEmpty()) {
            return 0;
        }

        $resultsByRiderId = $results->keyBy('rider_id');
        $stages = is_array($race->stages_json) ? $race->stages_json : [];
        $stageMeta = $predictionType === 'stage'
            ? collect($stages)->first(fn ($s) => (int) ($s['number'] ?? 0) === $stageNumber) ?? []
            : [];

        $saved = 0;

        foreach ($predictions as $prediction) {
            /** @var RaceResult|null $result */
            $result = $resultsByRiderId->get($prediction->rider_id);
            if (!$result) {
                continue;
            }

            TrainingExample::updateOrCreate(
                [
                    'race_id' => $race->id,
                    'rider_id' => $prediction->rider_id,
                    'prediction_type' => $predictionType,
                    'stage_number' => $stageNumber,
                    'model_version' => $prediction->model_version,
                ],
                [
                    'predicted_position' => $prediction->predicted_position,
                    'actual_position' => $result->position,
                    'actual_status' => $result->status,
                    'race_slug' => $race->pcs_slug,
                    'race_year' => (int) $race->year,
                    'race_category' => $race->category,
                    'race_type' => $race->race_type,
                    'parcours_type' => $stageMeta['parcours_type'] ?? $race->parcours_type,
                    'stage_subtype' => $stageMeta['stage_subtype'] ?? null,
                    'features' => $prediction->features,
                    'evaluated_at' => now(),
                ]
            );
            $saved++;
        }

        return $saved;
    }

    public function persistAllAvailableContexts(Race $race): int
    {
        $race->refresh();

        $contexts = $this->availableContextsFromResults($race);
        $saved = 0;
        foreach ($contexts as $ctx) {
            $saved += $this->persistRaceContext($race, $ctx['type'], $ctx['stage']);
        }

        return $saved;
    }

    /**
     * Build contexts that actually exist in race_results.
     *
     * @return Collection<int, array{type:string,stage:int}>
     */
    private function availableContextsFromResults(Race $race): Collection
    {
        $rows = $race->results()
            ->select('result_type', 'stage_number')
            ->distinct()
            ->get();

        return $rows->map(function ($row) {
            return [
                'type' => (string) $row->result_type,
                'stage' => (int) ($row->stage_number ?? 0),
            ];
        })
            ->filter(fn ($ctx) => $ctx['type'] !== '')
            ->values();
    }
}

