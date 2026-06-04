<?php

namespace App\Services;

use App\Models\Prediction;
use App\Models\Race;
use Illuminate\Support\Collection;

class PredictionCalibrationService
{
    private array $profiles = [];

    public function calibrateProbabilities(
        Race $race,
        int $predictedPosition,
        float $rawWinProbability,
        float $rawTop10Probability,
        string $predictionType,
        int $stageNumber = 0,
        ?string $stageSubtype = null,
        ?string $parcoursType = null,
    ): array {
        if ($predictionType === 'result' && $race->isOneDay()) {
            return $this->calibrateOneDayResultProbabilities(
                $race,
                $predictedPosition,
                $rawWinProbability,
                $rawTop10Probability,
            );
        }

        if ($predictionType === 'stage' && $race->isStageRace()) {
            return $this->calibrateStageRaceStageProbabilities(
                $race,
                $predictedPosition,
                $rawWinProbability,
                $rawTop10Probability,
                $stageNumber,
                $stageSubtype,
                $parcoursType,
            );
        }

        return [
            'win_probability' => $rawWinProbability,
            'top10_probability' => $rawTop10Probability,
            'calibration' => null,
        ];
    }

    public function calibrateOneDayResultProbabilities(
        Race $race,
        int $predictedPosition,
        float $rawWinProbability,
        float $rawTop10Probability,
    ): array {
        if (!$race->isOneDay()) {
            return [
                'win_probability' => $rawWinProbability,
                'top10_probability' => $rawTop10Probability,
                'calibration' => null,
            ];
        }

        $profile = $this->profileForCutoff($race->start_date->toDateString());
        $bucket = $this->bucketForPosition($predictedPosition);
        $stats = $profile[$bucket] ?? null;

        if ($stats === null || ($stats['samples'] ?? 0) < 10) {
            return [
                'win_probability' => $rawWinProbability,
                'top10_probability' => $rawTop10Probability,
                'calibration' => [
                    'bucket' => $bucket,
                    'samples' => $stats['samples'] ?? 0,
                    'applied' => false,
                ],
            ];
        }

        $winProbability = $this->applyMultiplier(
            $rawWinProbability,
            $stats['avg_raw_win_probability'],
            $stats['actual_win_rate'],
            (int) $stats['samples'],
            18,
            0.55,
            1.45,
            0.95
        );

        $top10Probability = $this->applyMultiplier(
            $rawTop10Probability,
            $stats['avg_raw_top10_probability'],
            $stats['actual_top10_rate'],
            (int) $stats['samples'],
            14,
            0.70,
            1.30,
            0.99
        );

        return [
            'win_probability' => $winProbability,
            'top10_probability' => max($winProbability, $top10Probability),
            'calibration' => [
                'bucket' => $bucket,
                'samples' => (int) $stats['samples'],
                'applied' => true,
                'actual_win_rate' => $stats['actual_win_rate'],
                'actual_top10_rate' => $stats['actual_top10_rate'],
                'avg_raw_win_probability' => $stats['avg_raw_win_probability'],
                'avg_raw_top10_probability' => $stats['avg_raw_top10_probability'],
            ],
        ];
    }

    public function calibrateStageRaceStageProbabilities(
        Race $race,
        int $predictedPosition,
        float $rawWinProbability,
        float $rawTop10Probability,
        int $stageNumber = 0,
        ?string $stageSubtype = null,
        ?string $parcoursType = null,
    ): array {
        if (!$race->isStageRace()) {
            return [
                'win_probability' => $rawWinProbability,
                'top10_probability' => $rawTop10Probability,
                'calibration' => null,
            ];
        }

        $subtype = $this->normalizeStageSubtype($stageSubtype, $parcoursType);
        $profile = $this->stageRaceStageProfileForCutoff($race->start_date->toDateString(), $subtype);
        $bucket = $this->bucketForPosition($predictedPosition);
        $stats = $profile[$bucket] ?? null;

        if ($stats === null || ($stats['samples'] ?? 0) < 8) {
            return [
                'win_probability' => $rawWinProbability,
                'top10_probability' => $rawTop10Probability,
                'calibration' => [
                    'profile' => 'stage_race_stage',
                    'stage_subtype' => $subtype,
                    'stage_number' => $stageNumber,
                    'bucket' => $bucket,
                    'samples' => $stats['samples'] ?? 0,
                    'applied' => false,
                ],
            ];
        }

        $winProbability = $this->applyMultiplier(
            $rawWinProbability,
            (float) $stats['avg_raw_win_probability'],
            (float) $stats['actual_win_rate'],
            (int) $stats['samples'],
            14,
            0.45,
            1.25,
            0.72
        );

        $top10Probability = $this->applyMultiplier(
            $rawTop10Probability,
            (float) $stats['avg_raw_top10_probability'],
            (float) $stats['actual_top10_rate'],
            (int) $stats['samples'],
            12,
            0.65,
            1.20,
            0.96
        );

        return [
            'win_probability' => $winProbability,
            'top10_probability' => max($winProbability, $top10Probability),
            'calibration' => [
                'profile' => 'stage_race_stage',
                'stage_subtype' => $subtype,
                'stage_number' => $stageNumber,
                'bucket' => $bucket,
                'samples' => (int) $stats['samples'],
                'applied' => true,
                'actual_win_rate' => $stats['actual_win_rate'],
                'actual_top10_rate' => $stats['actual_top10_rate'],
                'avg_raw_win_probability' => $stats['avg_raw_win_probability'],
                'avg_raw_top10_probability' => $stats['avg_raw_top10_probability'],
            ],
        ];
    }

    private function profileForCutoff(string $cutoffDate): array
    {
        if (array_key_exists($cutoffDate, $this->profiles)) {
            return $this->profiles[$cutoffDate];
        }

        $rows = Prediction::query()
            ->select(
                'predictions.predicted_position',
                'predictions.raw_win_probability',
                'predictions.raw_top10_probability',
                'race_results.position as actual_position'
            )
            ->join('races', 'races.id', '=', 'predictions.race_id')
            ->join('race_results', function ($join) {
                $join->on('race_results.race_id', '=', 'predictions.race_id')
                    ->on('race_results.rider_id', '=', 'predictions.rider_id')
                    ->whereColumn('race_results.result_type', 'predictions.prediction_type');
            })
            ->where('races.race_type', 'one_day')
            ->where('predictions.prediction_type', 'result')
            ->where('predictions.stage_number', 0)
            ->whereNull('race_results.stage_number')
            ->whereDate('races.end_date', '<', $cutoffDate)
            ->where('race_results.status', 'finished')
            ->whereNotNull('race_results.position')
            ->get();

        $profile = $rows
            ->groupBy(fn ($row) => $this->bucketForPosition((int) $row->predicted_position))
            ->map(function (Collection $bucketRows) {
                return [
                    'samples' => $bucketRows->count(),
                    'avg_raw_win_probability' => (float) $bucketRows->avg(fn ($row) => (float) ($row->raw_win_probability ?? 0)),
                    'avg_raw_top10_probability' => (float) $bucketRows->avg(fn ($row) => (float) ($row->raw_top10_probability ?? 0)),
                    'actual_win_rate' => (float) $bucketRows->filter(fn ($row) => (int) $row->actual_position === 1)->count() / max($bucketRows->count(), 1),
                    'actual_top10_rate' => (float) $bucketRows->filter(fn ($row) => (int) $row->actual_position <= 10)->count() / max($bucketRows->count(), 1),
                ];
            })
            ->all();

        return $this->profiles[$cutoffDate] = $profile;
    }

    private function stageRaceStageProfileForCutoff(string $cutoffDate, string $stageSubtype): array
    {
        $cacheKey = $cutoffDate . '|stage|' . $stageSubtype;
        if (array_key_exists($cacheKey, $this->profiles)) {
            return $this->profiles[$cacheKey];
        }

        $relatedSubtypes = $this->relatedStageSubtypes($stageSubtype);

        $rows = Prediction::query()
            ->select(
                'predictions.predicted_position',
                'predictions.raw_win_probability',
                'predictions.raw_top10_probability',
                'predictions.features',
                'race_results.position as actual_position'
            )
            ->join('races', 'races.id', '=', 'predictions.race_id')
            ->join('race_results', function ($join) {
                $join->on('race_results.race_id', '=', 'predictions.race_id')
                    ->on('race_results.rider_id', '=', 'predictions.rider_id')
                    ->on('race_results.stage_number', '=', 'predictions.stage_number')
                    ->whereColumn('race_results.result_type', 'predictions.prediction_type');
            })
            ->where('races.race_type', 'stage_race')
            ->where('predictions.prediction_type', 'stage')
            ->where('predictions.stage_number', '>', 0)
            ->whereDate('races.end_date', '<', $cutoffDate)
            ->where('race_results.status', 'finished')
            ->whereNotNull('race_results.position')
            ->get()
            ->filter(function ($row) use ($relatedSubtypes) {
                $features = is_array($row->features) ? $row->features : json_decode((string) $row->features, true);
                $rowSubtype = $this->normalizeStageSubtype(
                    $features['stage_subtype'] ?? null,
                    $features['parcours_type'] ?? null,
                );

                return in_array($rowSubtype, $relatedSubtypes, true);
            })
            ->values();

        $profile = $rows
            ->groupBy(fn ($row) => $this->bucketForPosition((int) $row->predicted_position))
            ->map(function (Collection $bucketRows) {
                return [
                    'samples' => $bucketRows->count(),
                    'avg_raw_win_probability' => (float) $bucketRows->avg(fn ($row) => (float) ($row->raw_win_probability ?? 0)),
                    'avg_raw_top10_probability' => (float) $bucketRows->avg(fn ($row) => (float) ($row->raw_top10_probability ?? 0)),
                    'actual_win_rate' => (float) $bucketRows->filter(fn ($row) => (int) $row->actual_position === 1)->count() / max($bucketRows->count(), 1),
                    'actual_top10_rate' => (float) $bucketRows->filter(fn ($row) => (int) $row->actual_position <= 10)->count() / max($bucketRows->count(), 1),
                ];
            })
            ->all();

        return $this->profiles[$cacheKey] = $profile;
    }

    private function bucketForPosition(int $predictedPosition): string
    {
        if ($predictedPosition <= 5) {
            return 'rank_' . $predictedPosition;
        }

        if ($predictedPosition <= 10) {
            return 'rank_6_10';
        }

        if ($predictedPosition <= 20) {
            return 'rank_11_20';
        }

        return 'rank_21_plus';
    }

    private function normalizeStageSubtype(?string $stageSubtype, ?string $parcoursType): string
    {
        $subtype = strtolower(trim((string) ($stageSubtype ?? '')));
        if ($subtype !== '') {
            return $subtype;
        }

        return match (strtolower(trim((string) ($parcoursType ?? '')))) {
            'flat' => 'sprint',
            'mountain' => 'high_mountain',
            'tt' => 'tt',
            'hilly' => 'hilly',
            default => 'mixed',
        };
    }

    private function relatedStageSubtypes(string $stageSubtype): array
    {
        return match ($stageSubtype) {
            'sprint', 'reduced_sprint' => ['sprint', 'reduced_sprint'],
            'summit_finish', 'high_mountain' => ['summit_finish', 'high_mountain'],
            'mixed', 'hilly' => ['mixed', 'hilly'],
            'tt', 'ttt' => ['tt', 'ttt'],
            default => [$stageSubtype],
        };
    }

    private function applyMultiplier(
        float $rawProbability,
        float $averageRawProbability,
        float $actualRate,
        int $samples,
        int $fullStrengthAt,
        float $minMultiplier,
        float $maxMultiplier,
        float $maxProbability,
    ): float {
        if ($rawProbability <= 0 || $averageRawProbability <= 0) {
            return min($maxProbability, max(0.0, $rawProbability));
        }

        $baseMultiplier = $actualRate / max($averageRawProbability, 0.0001);
        $smoothedStrength = min(1.0, $samples / max($fullStrengthAt, 1));
        $multiplier = 1 + (($baseMultiplier - 1) * $smoothedStrength);
        $multiplier = max($minMultiplier, min($maxMultiplier, $multiplier));

        return round(min($maxProbability, max(0.0, $rawProbability * $multiplier)), 4);
    }
}
