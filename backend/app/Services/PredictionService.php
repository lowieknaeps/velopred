<?php

namespace App\Services;

use App\Models\Prediction;
use App\Models\Race;
use App\Models\RaceEntry;
use App\Models\Rider;
use App\Models\RaceResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Berekent features voor elke renner in de startlijst van een race
 * en stuurt die naar de Python AI-service voor een voorspelling.
 * Slaat de resultaten op in de predictions tabel.
 */
class PredictionService
{
    /**
     * Per race/subtype keep track of previously predicted stage favourites.
     * Used to avoid "same winner every stage" artifacts.
     *
     * @var array<string, array<string,int>>
     */
    private array $stageFavouriteCounts = [];
    const CURRENT_YEAR_BOOST = 3.0;  // huidig jaar: 3x gewicht
    const DECAY              = 0.45; // voor jaren daarvoor
    const MIN_YEAR           = 2019;
    const MIN_PRIOR          = 3;
    private array $pcsSeasonSignalsCache = [];
    private array $topCompetitorCache = [];
    private array $raceTeamSignalsCache = [];

    public function __construct(
        private ExternalCyclingApiService $api,
        private RiderSyncService $riderSync,
        private PredictionCalibrationService $calibration,
    ) {}

    /**
     * Genereert voorspellingen voor een race en slaat ze op.
     * Verwerkt renners in batches van 50 om geheugengebruik te beperken.
     */
    public function predictRace(Race $race): int
    {
        Log::info("[Prediction] Start: {$race->name} {$race->year}");
        $this->stageFavouriteCounts = [];

        $riderIds = $this->getPredictionRiderIds($race);

        if ($riderIds->isEmpty()) {
            Log::warning("[Prediction] Geen renners gevonden voor {$race->name}");
            return 0;
        }

        $contexts = $this->buildPredictionContexts($race);
        $saved = 0;

        foreach ($contexts as $context) {
            $payload = [];
            $featuresBySlug = [];

            foreach ($riderIds->chunk(50) as $chunk) {
                $riders = Rider::whereIn('id', $chunk)->get();
                foreach ($riders as $rider) {
                    if (!$this->isEligibleForPredictionContext($rider, $race, $context)) {
                        continue;
                    }

                    $features = $this->buildFeatures($rider, $race, $context, $riderIds->count());
                    $payload[] = $features;
                    $featuresBySlug[$rider->pcs_slug] = $features;
                }
                unset($riders);
            }

            if (empty($payload)) {
                continue;
            }

            $payload = $this->applyFieldRelativeFeatures($payload);
            $payload = $this->applyCompositeFeatures($payload);
            $featuresBySlug = collect($payload)
                ->keyBy('rider_slug')
                ->all();

            $response = $this->api->predictRace(
                $race->pcs_slug,
                $race->year,
                $context['parcours_type'],
                $payload,
                $context['prediction_type'],
                $context['stage_number'],
            );

            $predictions = $response['predictions'] ?? [];
            $modelVersion = $response['model_version'] ?? 'v1';
            if (empty($predictions)) {
                Log::warning("[Prediction] Lege response voor {$race->name} {$race->year} {$context['prediction_type']} {$context['stage_number']}; bestaande voorspellingen blijven behouden.");
                continue;
            }

            $slugToId = Rider::whereIn('pcs_slug', collect($predictions)->pluck('rider_slug'))
                ->pluck('id', 'pcs_slug');
            $predictions = $this->applyTeamHierarchyAdjustments(
                $predictions,
                $race,
                $slugToId,
                $featuresBySlug,
                $context['prediction_type'],
            );
            $predictions = $this->applyClassicContenderMomentumAdjustments(
                $predictions,
                $race,
                $featuresBySlug,
                $context['prediction_type'],
            );
            $predictions = $this->applyStageDiversityAdjustments(
                $predictions,
                $race,
                $context,
                $featuresBySlug,
            );
            $savedRiderIds = [];

            $attempt = 0;
            $maxAttempts = 6;
            while (true) {
                try {
                    DB::transaction(function () use (
                        $predictions,
                        $slugToId,
                        $featuresBySlug,
                        $race,
                        $context,
                        $modelVersion,
                        &$saved,
                        &$savedRiderIds
                    ) {
                        foreach ($predictions as $pred) {
                            $slug    = $pred['rider_slug'];
                            $riderId = $slugToId[$slug] ?? null;
                            if (!$riderId) {
                                continue;
                            }

                            $savedRiderIds[] = (int) $riderId;

                            $rawWinProbability = (float) $pred['win_probability'];
                            $rawTop10Probability = (float) $pred['top10_probability'];
                            $calibrated = $context['prediction_type'] === 'result' && $race->isOneDay()
                                ? $this->calibration->calibrateOneDayResultProbabilities(
                                    $race,
                                    (int) $pred['predicted_position'],
                                    $rawWinProbability,
                                    $rawTop10Probability,
                                )
                                : [
                                    'win_probability' => $rawWinProbability,
                                    'top10_probability' => $rawTop10Probability,
                                    'calibration' => null,
                                ];

                            $featureSet = $featuresBySlug[$slug] ?? $pred['features'];
                            if (is_array($featureSet)) {
                                $featureSet['raw_win_probability'] = $rawWinProbability;
                                $featureSet['raw_top10_probability'] = $rawTop10Probability;
                                if ($calibrated['calibration']) {
                                    $featureSet['probability_calibration'] = $calibrated['calibration'];
                                }
                            }

                            $predictionRecord = Prediction::updateOrCreate(
                                [
                                    'race_id' => $race->id,
                                    'rider_id' => $riderId,
                                    'prediction_type' => $context['prediction_type'],
                                    'stage_number' => $context['stage_number'],
                                ],
                                [
                                    'model_version' => $modelVersion,
                                    'predicted_position' => $pred['predicted_position'],
                                    'top10_probability' => $calibrated['top10_probability'],
                                    'raw_top10_probability' => $rawTop10Probability,
                                    'win_probability' => $calibrated['win_probability'],
                                    'raw_win_probability' => $rawWinProbability,
                                    'confidence_score' => $pred['confidence_score'],
                                    'features' => $featureSet,
                                ]
                            );
                            // Zorg dat reruns altijd zichtbaar zijn in "Voorspellingen vernieuwd",
                            // zelfs wanneer de modeloutput inhoudelijk identiek blijft.
                            $predictionRecord->touch();
                            $saved++;
                        }

                        Prediction::where('race_id', $race->id)
                            ->where('prediction_type', $context['prediction_type'])
                            ->where('stage_number', $context['stage_number'])
                            ->when(!empty($savedRiderIds), fn ($query) => $query->whereNotIn('rider_id', array_values(array_unique($savedRiderIds))))
                            ->delete();
                    });
                    break;
                } catch (\Throwable $e) {
                    $attempt++;
                    $message = strtolower($e->getMessage());
                    $locked = str_contains($message, 'database is locked') || str_contains($message, 'sqlite') && str_contains($message, 'locked');
                    if (!$locked || $attempt >= $maxAttempts) {
                        throw $e;
                    }

                    // Backoff (SQLite lock contention).
                    usleep(250000 * $attempt);
                }
            }

            Log::info("[Prediction] {$race->name} {$race->year} {$context['prediction_type']} {$context['stage_number']}: " . count($predictions));
        }

        if ($race->isStageRace()) {
            $this->refreshStageRaceClassificationsFromStages($race);
        }

        Log::info("[Prediction] {$saved} voorspellingen opgeslagen voor {$race->name}");
        return $saved;
    }

    private function refreshStageRaceClassificationsFromStages(Race $race): void
    {
        $stages = is_array($race->stages_json) ? $race->stages_json : [];
        if (empty($stages)) {
            return;
        }

        $stageSubtypesByNumber = collect($stages)
            ->mapWithKeys(fn (array $s) => [(int) ($s['number'] ?? 0) => (string) ($s['stage_subtype'] ?? '')])
            ->filter(fn ($v, $k) => (int) $k > 0 && $v !== '')
            ->all();

        if (empty($stageSubtypesByNumber)) {
            return;
        }

        $stagePredictions = Prediction::query()
            ->where('race_id', $race->id)
            ->where('prediction_type', 'stage')
            ->where('stage_number', '>', 0)
            ->get(['id', 'rider_id', 'stage_number', 'win_probability', 'top10_probability']);

        if ($stagePredictions->isEmpty()) {
            return;
        }

        $gcPredictionsByRider = Prediction::query()
            ->where('race_id', $race->id)
            ->where('prediction_type', 'gc')
            ->where('stage_number', 0)
            ->get(['rider_id', 'predicted_position', 'win_probability'])
            ->keyBy('rider_id');

        $pointsScores = [];
        $komScores = [];

        foreach ($stagePredictions as $pred) {
            $stageNr = (int) $pred->stage_number;
            $subtype = $stageSubtypesByNumber[$stageNr] ?? '';
            if ($subtype === '') {
                continue;
            }

            $riderId = (int) $pred->rider_id;
            $win = (float) ($pred->win_probability ?? 0.0);
            $top10 = (float) ($pred->top10_probability ?? 0.0);

            if (in_array($subtype, ['sprint', 'reduced_sprint'], true)) {
                $w = $subtype === 'sprint' ? 1.00 : 0.72;
                $pointsScores[$riderId] = ($pointsScores[$riderId] ?? 0.0) + $w * (($win * 110.0) + ($top10 * 30.0));
            }

            if (in_array($subtype, ['summit_finish', 'high_mountain'], true)) {
                $w = $subtype === 'high_mountain' ? 1.00 : 0.78;
                $komScores[$riderId] = ($komScores[$riderId] ?? 0.0) + $w * (($win * 95.0) + ($top10 * 26.0));
            }
        }

        if (!empty($pointsScores)) {
            $this->upsertClassificationFromScores($race, 'points', $pointsScores);
        }

        if (!empty($komScores)) {
            // KOM should favour strong climbers not going for GC.
            foreach ($komScores as $riderId => $score) {
                $gcPos = (int) ($gcPredictionsByRider->get($riderId)?->predicted_position ?? 999);
                $gcWin = (float) ($gcPredictionsByRider->get($riderId)?->win_probability ?? 0.0);
                $mult = 1.0;
                if ($gcPos <= 2) {
                    $mult = 0.18;
                } elseif ($gcPos <= 3) {
                    $mult = 0.28;
                } elseif ($gcPos <= 5) {
                    $mult = 0.45;
                } elseif ($gcPos <= 10) {
                    $mult = 0.70;
                } elseif ($gcPos <= 15) {
                    $mult = 0.85;
                }
                if ($gcWin >= 0.12) {
                    $mult *= 0.55;
                } elseif ($gcWin >= 0.06) {
                    $mult *= 0.75;
                }
                $komScores[$riderId] = $score * $mult;
            }
            $this->upsertClassificationFromScores($race, 'kom', $komScores);
        }
    }

    private function upsertClassificationFromScores(Race $race, string $type, array $scoresByRiderId): void
    {
        if (empty($scoresByRiderId)) {
            return;
        }

        arsort($scoresByRiderId);
        $riderIds = array_keys($scoresByRiderId);
        $n = max(1, count($riderIds));

        // Convert scores to a probability distribution (softmax-ish, stable).
        $scores = array_values($scoresByRiderId);
        $max = max($scores);
        $exp = array_map(fn ($s) => exp(($s - $max) / 18.0), $scores);
        $sum = array_sum($exp) ?: 1.0;

        DB::transaction(function () use ($race, $type, $riderIds, $exp, $sum, $n) {
            foreach ($riderIds as $i => $riderId) {
                $winProb = (float) ($exp[$i] / $sum);
                $top10Prob = (float) max(0.02, min(0.95, exp(-$i * 0.12) * 0.85));

                $record = Prediction::updateOrCreate(
                    [
                        'race_id' => $race->id,
                        'rider_id' => (int) $riderId,
                        'prediction_type' => $type,
                        'stage_number' => 0,
                    ],
                    [
                        'model_version' => (string) (Prediction::where('race_id', $race->id)->value('model_version') ?? 'v1'),
                        'predicted_position' => $i + 1,
                        'top10_probability' => $top10Prob,
                        'raw_top10_probability' => $top10Prob,
                        'win_probability' => $winProb,
                        'raw_win_probability' => $winProb,
                        'confidence_score' => 0.70,
                        'features' => [
                            'derived_from_stages' => true,
                            'stage_race_classification' => $type,
                        ],
                    ]
                );
                $record->touch();
            }
        });
    }

    private function applyStageDiversityAdjustments(
        array $predictions,
        Race $race,
        array $context,
        array $featuresBySlug,
    ): array {
        if (($context['prediction_type'] ?? '') !== 'stage' || !$race->isStageRace() || count($predictions) < 2) {
            return $predictions;
        }

        $stageSubtype = (string) ($context['stage_subtype'] ?? '');
        if ($stageSubtype === '') {
            return $predictions;
        }

        // Only apply to sprint-like stages where the repetition artifact is most visible.
        if (!in_array($stageSubtype, ['sprint', 'reduced_sprint'], true)) {
            return $predictions;
        }

        $raceKey = $race->id . ':' . $stageSubtype;
        $counts = $this->stageFavouriteCounts[$raceKey] ?? [];

        foreach ($predictions as $i => $pred) {
            $slug = (string) ($pred['rider_slug'] ?? '');
            if ($slug === '' || !isset($counts[$slug]) || $counts[$slug] <= 0) {
                continue;
            }

            $prev = (int) $counts[$slug];
            $factor = max(0.40, pow(0.70, $prev));
            $predictions[$i]['win_probability'] = max(0.0, min(1.0, (float) ($pred['win_probability'] ?? 0.0) * $factor));
            $predictions[$i]['top10_probability'] = max(0.0, min(1.0, (float) ($pred['top10_probability'] ?? 0.0) * (0.92 + ($factor * 0.08))));
        }

        // Re-sort and re-rank after adjustment.
        usort($predictions, function (array $a, array $b) {
            $aWin = (float) ($a['win_probability'] ?? 0.0);
            $bWin = (float) ($b['win_probability'] ?? 0.0);
            if ($aWin !== $bWin) {
                return $bWin <=> $aWin;
            }

            $aTop10 = (float) ($a['top10_probability'] ?? 0.0);
            $bTop10 = (float) ($b['top10_probability'] ?? 0.0);
            if ($aTop10 !== $bTop10) {
                return $bTop10 <=> $aTop10;
            }

            return ((int) ($a['predicted_position'] ?? 999)) <=> ((int) ($b['predicted_position'] ?? 999));
        });

        foreach ($predictions as $index => $prediction) {
            $predictions[$index]['predicted_position'] = $index + 1;
        }

        // Update favourite counts for next stages of the same subtype.
        $winnerSlug = (string) ($predictions[0]['rider_slug'] ?? '');
        if ($winnerSlug !== '') {
            $counts[$winnerSlug] = (int) ($counts[$winnerSlug] ?? 0) + 1;
            $this->stageFavouriteCounts[$raceKey] = $counts;
        }

        return $predictions;
    }

    public function refreshRaceIfStale(Race $race): bool
    {
        // Vermijd zware sync/predict calls op pagina-load voor afgelopen races.
        // Deze worden via scheduler/post-result-jobs verwerkt.
        if ($race->hasFinished()) {
            return false;
        }

        $latestPrediction = $race->predictions()->latest('updated_at')->first();
        $latestPredictionAt = $latestPrediction?->updated_at;
        $latestResultAt = $race->results()->latest('updated_at')->value('updated_at');

        $needsRefresh = $latestPredictionAt === null;

        if (!$needsRefresh && $race->startlist_synced_at !== null && $race->startlist_synced_at->gt($latestPredictionAt)) {
            $needsRefresh = true;
        }

        if (!$needsRefresh && $latestResultAt !== null && now()->parse($latestResultAt)->gt($latestPredictionAt)) {
            $needsRefresh = true;
        }

        if (
            !$needsRefresh
            && $race->hasStarted()
            && !$race->hasFinished()
            && $latestPredictionAt !== null
            && $latestPredictionAt->lt(now()->subMinutes(20))
        ) {
            $needsRefresh = true;
        }

        if (!$needsRefresh) {
            return false;
        }

        try {
            $this->predictRace($race->fresh());
            return true;
        } catch (\Throwable $e) {
            Log::warning("[Prediction] Automatische page refresh mislukt voor {$race->name} {$race->year}: {$e->getMessage()}");
            return false;
        }
    }

    // ── Feature engineering ───────────────────────────────────────────────────

    private function buildFeatures(Rider $rider, Race $race, array $context, int $fieldSize): array
    {
        $currentYear = (int) $race->start_date->format('Y');
        $raceDate    = $race->start_date->toDateString();
        $predictionType = $context['prediction_type'];
        $stageNumber = (int) ($context['stage_number'] ?? 0);
        $contextParcoursType = $context['parcours_type'] ?? $race->parcours_type;
        $contextStageSubtype = $context['stage_subtype'] ?? $this->defaultStageSubtypeForParcours($contextParcoursType);
        $relatedStageSubtypes = $this->relatedStageSubtypes($contextStageSubtype);
        $raceDays = max(1, $race->start_date->diffInDays($race->end_date) + 1);
        $categoryWeight = $this->categoryWeight($race->category);
        $predictionTypeCode = $this->predictionTypeCode($predictionType);
        $stageSubtypeCode = $this->stageSubtypeCode($contextStageSubtype);
        $pcsTopCompetitor = $this->topCompetitorMetadata($race, $rider->pcs_slug);
        $pcsSeasonSignals = $this->pcsSeasonSignals($rider, $race, $pcsTopCompetitor);
        $manualIncident = $this->manualIncidentSignal($rider->pcs_slug, $race->start_date);
        $raceDynamics = $this->manualRaceDynamicsSignal($rider->pcs_slug, $race);
        $combinedIncidentPenalty = min(1.0, max(0.0, (float) $manualIncident['penalty'] + (float) $raceDynamics['incident_penalty']));
        $combinedIncidentDaysAgo = match (true) {
            is_numeric($manualIncident['days_ago'] ?? null) && is_numeric($raceDynamics['days_ago'] ?? null) => min(
                (int) $manualIncident['days_ago'],
                (int) $raceDynamics['days_ago']
            ),
            is_numeric($manualIncident['days_ago'] ?? null) => (int) $manualIncident['days_ago'],
            is_numeric($raceDynamics['days_ago'] ?? null) => (int) $raceDynamics['days_ago'],
            default => null,
        };
        $teamSignals = $this->raceTeamSignals($race, $rider);
        $currentRaceResults = $race->isOneDay()
            ? collect()
            : RaceResult::where('race_id', $race->id)
                ->where('rider_id', $rider->id)
                ->whereNotNull('position')
                ->where('status', 'finished')
                ->get(['result_type', 'stage_number', 'position']);
        $liveStageResults = $currentRaceResults->where('result_type', 'stage')->values();
        $liveClassification = $currentRaceResults->first(function ($result) use ($predictionType, $stageNumber) {
            if ($result->result_type !== $predictionType) {
                return false;
            }

            if ($predictionType !== 'stage') {
                return true;
            }

            return (int) $result->stage_number === $stageNumber;
        });
        $liveStageResultsCount = $liveStageResults->count();
        $liveStageAveragePosition = $liveStageResultsCount > 0 ? round($liveStageResults->avg('position'), 2) : null;
        $liveStageBestPosition = $liveStageResultsCount > 0 ? (int) $liveStageResults->min('position') : null;
        $liveStageTop10Count = $liveStageResults->filter(fn ($result) => (int) $result->position <= 10)->count();
        $liveClassificationPosition = $liveClassification?->position;

        // Historische resultaten vóór deze race
        $prior = RaceResult::where('rider_id', $rider->id)
            ->whereIn('result_type', ['result', 'stage', 'gc', 'points', 'kom', 'youth'])
            ->whereNotNull('position')
            ->where('status', 'finished')
            ->where('position', '<=', 100)
            ->join('races', 'race_results.race_id', '=', 'races.id')
            ->where('races.start_date', '<', $raceDate)
            ->where('races.year', '>=', self::MIN_YEAR)
            ->orderBy('races.start_date', 'asc')
            ->select(
                'race_results.race_id',
                'race_results.position',
                'race_results.gap_seconds',
                'race_results.result_type',
                'race_results.stage_number',
                'races.year as race_year',
                'races.start_date as race_start_date',
                'races.parcours_type',
                'races.pcs_slug as race_slug',
                'races.stages_json'
            )
            ->get();

        $prior = $prior->map(function ($result) {
            $result->context_parcours_type = $this->resolveResultParcoursType(
                $result->result_type,
                $result->parcours_type,
                $result->stages_json,
                (int) ($result->stage_number ?? 0),
            );
            $result->context_stage_subtype = $this->resolveResultStageSubtype(
                $result->result_type,
                $result->parcours_type,
                $result->stages_json,
                (int) ($result->stage_number ?? 0),
            );

            return $result;
        });

        $contextPrior = $prior->filter(
            fn($result) => in_array(
                $result->result_type,
                $this->historyResultTypes($predictionType, $contextParcoursType, $contextStageSubtype),
                true
            )
        )->values();
        if ($contextPrior->count() < self::MIN_PRIOR) {
            $contextPrior = $prior->filter(fn($result) => in_array($result->result_type, $this->fallbackHistoryResultTypes(), true))->values();
        }

        if ($contextPrior->isEmpty()) {
            return [
                'rider_slug'              => $rider->pcs_slug,
                'prediction_type'         => $predictionType,
                'stage_number'            => $stageNumber,
                'field_size'              => $fieldSize,
                'race_days'               => $raceDays,
                'category_weight'         => $categoryWeight,
                'prediction_type_code'    => $predictionTypeCode,
                'stage_subtype'           => $contextStageSubtype,
                'stage_subtype_code'      => $stageSubtypeCode,
                'field_pct_career_points' => 0.5,
                'field_pct_pcs_ranking'   => 0.5,
                'field_pct_uci_ranking'   => 0.5,
                'field_pct_recent_form'   => 0.5,
                'field_pct_season_form'   => 0.5,
                'field_pct_course_fit'    => 0.5,
                'field_pct_top10_rate'    => 0.5,
                'favourite_score'         => 50.0,
                'specialist_score'        => 50.0,
                'season_dominance_score'  => 50.0,
                'avg_position'            => null,
                'avg_position_parcours'   => null,
                'avg_position_stage_subtype' => null,
                'recent_avg_position_parcours' => null,
                'recent_avg_position_stage_subtype' => null,
                'recent_top10_rate_parcours' => null,
                'recent_top10_rate_stage_subtype' => null,
                'top10_rate'              => null,
                'form_trend'              => null,
                'age'                     => $rider->age,
                'career_points'           => $rider->career_points,
                'pcs_ranking'             => $rider->pcs_ranking,
                'uci_ranking'             => $rider->uci_ranking,
                'recent_avg_position'     => null,
                'recent_top10_rate'       => null,
                'top10_last_10_rate'      => null,
                'recency_weighted_avg_position_10' => null,
                'avg_position_this_race'  => null,
                'best_result_this_race'   => null,
                'wins_this_race'          => 0,
                'podiums_this_race'       => 0,
                'current_year_avg_position' => null,
                'current_year_top10_rate' => null,
                'current_year_close_finish_rate' => null,
                'current_year_attack_momentum_rate' => null,
                'current_year_avg_position_parcours' => null,
                'current_year_top10_rate_parcours' => null,
                'current_year_close_finish_rate_parcours' => null,
                'current_year_attack_momentum_rate_parcours' => null,
                'current_year_avg_position_stage_subtype' => null,
                'current_year_top10_rate_stage_subtype' => null,
                'sprint_profile_score' => 25.0,
                'punch_profile_score' => 25.0,
                'climb_profile_score' => 25.0,
                'tt_profile_score' => 25.0,
                'sprint_profile_experience' => 0.0,
                'punch_profile_experience' => 0.0,
                'climb_profile_experience' => 0.0,
                'tt_profile_experience' => 0.0,
                'pcs_speciality_one_day' => $rider->pcs_speciality_one_day,
                'pcs_speciality_gc' => $rider->pcs_speciality_gc,
                'pcs_speciality_tt' => $rider->pcs_speciality_tt,
                'pcs_speciality_sprint' => $rider->pcs_speciality_sprint,
                'pcs_speciality_climber' => $rider->pcs_speciality_climber,
                'pcs_speciality_hills' => $rider->pcs_speciality_hills,
                'wins_current_year'       => 0,
                'podiums_current_year'    => 0,
                'current_year_results_count' => 0,
                'parcours_results_count'  => 0,
                'stage_subtype_results_count' => 0,
                'this_race_results_count' => 0,
                'race_specificity_ratio'  => 1.0,
                'pcs_top_competitor_rank' => $pcsTopCompetitor['rank'] ?? null,
                'pcs_top_competitor_points' => $pcsTopCompetitor['pcs_points'] ?? null,
                'pcs_top_competitor_pcs_ranking' => $pcsTopCompetitor['pcs_ranking'] ?? null,
                'pcs_recent_activity_count_30d' => $pcsSeasonSignals['recent_activity_count_30d'],
                'pcs_season_finished_count' => $pcsSeasonSignals['season_finished_count'],
                'pcs_season_top10_rate' => $pcsSeasonSignals['season_top10_rate'],
                'pcs_small_race_wins' => $pcsSeasonSignals['small_race_wins'],
                'pcs_small_race_top10_rate' => $pcsSeasonSignals['small_race_top10_rate'],
                'pcs_recent_nonfinish_count_90d' => $pcsSeasonSignals['recent_nonfinish_count_90d'],
                'pcs_last_incident_days_ago' => $pcsSeasonSignals['last_incident_days_ago'],
                'pcs_comeback_finished_count' => $pcsSeasonSignals['comeback_finished_count'],
                'pcs_days_since_last_result' => $pcsSeasonSignals['days_since_last_result'],
                'team_startlist_size' => $teamSignals['team_startlist_size'],
                'team_career_points_total' => $teamSignals['team_career_points_total'],
                'team_career_points_share' => $teamSignals['team_career_points_share'],
                'manual_incident_penalty' => round($combinedIncidentPenalty, 4),
                'manual_incident_days_ago' => $combinedIncidentDaysAgo,
                'race_dynamics_form_adjustment' => round((float) $raceDynamics['form_adjustment'], 4),
                'race_dynamics_incident_penalty' => round((float) $raceDynamics['incident_penalty'], 4),
                'live_stage_results_count' => $liveStageResultsCount,
                'live_stage_avg_position' => $liveStageAveragePosition,
                'live_stage_best_position' => $liveStageBestPosition,
                'live_stage_top10_count' => $liveStageTop10Count,
                'live_classification_position' => $liveClassificationPosition,
                'recent_one_day_position' => null,
                'recent_one_day_days_ago' => null,
                'recent_one_day_momentum' => null,
                'n_results'               => 0,
            ];
        }

        // Gewichtsberekening: huidig jaar 3×, vorig jaar 1.0, ouder snel dalend
        $weights = $contextPrior->mapWithKeys(function ($r, $i) use ($currentYear) {
            $yearsAgo = max(0, $currentYear - $r->race_year);
            if ($yearsAgo === 0)      $w = self::CURRENT_YEAR_BOOST;
            elseif ($yearsAgo === 1)  $w = 1.0;
            else                      $w = pow(self::DECAY, $yearsAgo - 1);
            return [$i => $w];
        });
        $totalWeight = $weights->sum();

        // Gewogen gemiddelde positie
        $avgPos = $contextPrior->zip($weights)->map(fn($pair) => $pair[0]->position * $pair[1])->sum() / $totalWeight;

        // Gewogen top-10 rate
        $top10Rate = $contextPrior->zip($weights)->map(fn($pair) => ($pair[0]->position <= 10 ? 1 : 0) * $pair[1])->sum() / $totalWeight * 100;

        // Gewogen gem. op dit parcours type
        $parcoursGroup = $contextPrior->filter(fn($r) => $r->context_parcours_type === $contextParcoursType);
        $avgPosParcours = $parcoursGroup->isNotEmpty()
            ? $parcoursGroup->zip($weights->only($parcoursGroup->keys()))->map(fn($p) => $p[0]->position * $p[1])->sum()
              / $weights->only($parcoursGroup->keys())->sum()
            : $avgPos;
        $stageSubtypeGroup = $contextPrior->filter(
            fn($r) => in_array(($r->context_stage_subtype ?? null), $relatedStageSubtypes, true)
        );
        $avgPosStageSubtype = $stageSubtypeGroup->isNotEmpty()
            ? $stageSubtypeGroup->zip($weights->only($stageSubtypeGroup->keys()))->map(fn($p) => $p[0]->position * $p[1])->sum()
              / $weights->only($stageSubtypeGroup->keys())->sum()
            : $avgPosParcours;

        // Form trend: gem. laatste 5 races vs. alles
        $recent    = $contextPrior->take(-5);
        $recentAvg = $recent->isNotEmpty() ? $recent->avg('position') : $avgPos;
        $formTrend = $recentAvg - $avgPos;
        $last10 = $contextPrior->take(-10)->values();
        $top10Last10Rate = $last10->isNotEmpty()
            ? $last10->filter(fn($r) => $r->position <= 10)->count() / $last10->count() * 100
            : $top10Rate;
        $last10Weights = collect(range(1, max(1, $last10->count())));
        $recencyWeightedAvgPosition10 = $last10->isNotEmpty()
            ? $last10->zip($last10Weights)
                ->map(fn($pair) => $pair[0]->position * $pair[1])
                ->sum() / max(1, $last10Weights->sum())
            : $avgPos;
        $recentTop10Rate = $recent->isNotEmpty()
            ? $recent->filter(fn($r) => $r->position <= 10)->count() / $recent->count() * 100
            : $top10Rate;
        $recentParcours = $parcoursGroup->take(-5);
        $recentParcoursAvg = $recentParcours->isNotEmpty() ? $recentParcours->avg('position') : $avgPosParcours;
        $recentParcoursTop10Rate = $recentParcours->isNotEmpty()
            ? $recentParcours->filter(fn($r) => $r->position <= 10)->count() / $recentParcours->count() * 100
            : $top10Rate;
        $recentStageSubtype = $stageSubtypeGroup->take(-5);
        $recentStageSubtypeAvg = $recentStageSubtype->isNotEmpty() ? $recentStageSubtype->avg('position') : $avgPosStageSubtype;
        $recentStageSubtypeTop10Rate = $recentStageSubtype->isNotEmpty()
            ? $recentStageSubtype->filter(fn($r) => $r->position <= 10)->count() / $recentStageSubtype->count() * 100
            : $recentParcoursTop10Rate;
        $recentOneDayResult = $prior
            ->where('result_type', 'result')
            ->sortByDesc('race_start_date')
            ->first();
        $recentOneDayPosition = isset($recentOneDayResult?->position) && is_numeric($recentOneDayResult->position)
            ? (int) $recentOneDayResult->position
            : null;
        $recentOneDayDaysAgo = null;
        if (!empty($recentOneDayResult?->race_start_date)) {
            try {
                $recentOneDayDate = \Carbon\Carbon::parse((string) $recentOneDayResult->race_start_date)->startOfDay();
                $recentOneDayDaysAgo = $recentOneDayDate->diffInDays($race->start_date->copy()->startOfDay());
            } catch (\Throwable) {
                $recentOneDayDaysAgo = null;
            }
        }
        $recentOneDayMomentum = null;
        if ($recentOneDayPosition !== null) {
            $positionScore = match (true) {
                $recentOneDayPosition === 1 => 1.0,
                $recentOneDayPosition === 2 => 0.88,
                $recentOneDayPosition === 3 => 0.76,
                $recentOneDayPosition <= 5 => 0.58,
                $recentOneDayPosition <= 10 => 0.38,
                default => 0.0,
            };
            $daysDecay = $recentOneDayDaysAgo === null
                ? 0.75
                : max(0.0, 1.0 - ($recentOneDayDaysAgo / 40.0));
            $recentOneDayMomentum = round($positionScore * $daysDecay, 4);
        }

        // Race-specifieke features: normale decay (zonder current_year_boost)
        // zodat historische prestaties op déze koers nog meetellen (RVV-effect)
        $raceWeights = $contextPrior->mapWithKeys(function ($r, $i) use ($currentYear) {
            return [$i => pow(self::DECAY, max(0, $currentYear - $r->race_year))];
        });
        $thisRace = $contextPrior->filter(function ($result) use ($race, $predictionType, $relatedStageSubtypes) {
            if ($result->race_slug !== $race->pcs_slug || $result->result_type !== $predictionType) {
                return false;
            }

            if ($predictionType !== 'stage') {
                return true;
            }

            return in_array(($result->context_stage_subtype ?? null), $relatedStageSubtypes, true);
        });
        $avgThisRaceRaw = null;
        if ($thisRace->isNotEmpty()) {
            $rw = $raceWeights->only($thisRace->keys());
            $avgThisRaceRaw = $thisRace->zip($rw)->map(fn($p) => $p[0]->position * $p[1])->sum() / $rw->sum();
        }

        $courseHistoryFallback = match ($predictionType) {
            'stage' => $avgPosStageSubtype,
            'gc', 'youth', 'points', 'kom' => $avgPosParcours,
            default => $avgPos,
        };

        $avgThisRace = $this->stabilizeCourseHistoryAverage(
            $avgThisRaceRaw,
            $courseHistoryFallback,
            $thisRace->count(),
            $predictionType,
        );

        // Race-specifieke features
        $bestResultThisRace = $thisRace->isNotEmpty() ? (float) $thisRace->min('position') : $courseHistoryFallback;
        $winsThisRace       = $thisRace->where('position', 1)->count();
        $podiumsThisRace    = $thisRace->where('position', '<=', 3)->count();
        $thisRaceResultsCount = $thisRace->count();
        $secondPlaceGapMap = $this->secondPlaceGapMap($contextPrior);

        // Huidig seizoen telt apart mee zodat recente topvorm zichtbaar blijft
        $currentYearResults = $contextPrior->filter(fn($r) => (int) $r->race_year === $currentYear);
        if ($currentYearResults->isNotEmpty()) {
            $cyWeights = $weights->only($currentYearResults->keys());
            $currentYearAvgPosition = $currentYearResults->zip($cyWeights)
                ->map(fn($p) => $p[0]->position * $p[1])
                ->sum() / $cyWeights->sum();
            $currentYearTop10Rate = $currentYearResults->zip($cyWeights)
                ->map(fn($p) => ($p[0]->position <= 10 ? 1 : 0) * $p[1])
                ->sum() / $cyWeights->sum() * 100;
            $currentYearCloseFinishRate = $currentYearResults->zip($cyWeights)
                ->map(function ($p) {
                    $result = $p[0];
                    $weight = $p[1];
                    $isCloseFinish = $this->isCloseFinishResult($result->position, $result->gap_seconds);

                    return ($isCloseFinish ? 1 : 0) * $weight;
                })
                ->sum() / $cyWeights->sum() * 100;
            $currentYearAttackMomentumRate = $currentYearResults->zip($cyWeights)
                ->map(function ($p) use ($secondPlaceGapMap) {
                    $result = $p[0];
                    $weight = $p[1];

                    return $this->attackMomentumSignal($result, $secondPlaceGapMap) * $weight;
                })
                ->sum() / $cyWeights->sum() * 100;
            $winsCurrentYear    = $currentYearResults->where('position', 1)->count();
            $podiumsCurrentYear = $currentYearResults->where('position', '<=', 3)->count();
            $currentYearResultsCount = $currentYearResults->count();
        } else {
            $currentYearAvgPosition = null;
            $currentYearTop10Rate   = null;
            $currentYearCloseFinishRate = null;
            $currentYearAttackMomentumRate = null;
            $winsCurrentYear        = 0;
            $podiumsCurrentYear     = 0;
            $currentYearResultsCount = 0;
        }

        $currentYearParcoursResults = $currentYearResults->filter(
            fn($r) => $r->context_parcours_type === $contextParcoursType
        );
        if ($currentYearParcoursResults->isNotEmpty()) {
            $cyParcoursWeights = $weights->only($currentYearParcoursResults->keys());
            $currentYearAvgPositionParcours = $currentYearParcoursResults->zip($cyParcoursWeights)
                ->map(fn($p) => $p[0]->position * $p[1])
                ->sum() / $cyParcoursWeights->sum();
            $currentYearTop10RateParcours = $currentYearParcoursResults->zip($cyParcoursWeights)
                ->map(fn($p) => ($p[0]->position <= 10 ? 1 : 0) * $p[1])
                ->sum() / $cyParcoursWeights->sum() * 100;
            $currentYearCloseFinishRateParcours = $currentYearParcoursResults->zip($cyParcoursWeights)
                ->map(function ($p) {
                    $result = $p[0];
                    $weight = $p[1];
                    $isCloseFinish = $this->isCloseFinishResult($result->position, $result->gap_seconds);

                    return ($isCloseFinish ? 1 : 0) * $weight;
                })
                ->sum() / $cyParcoursWeights->sum() * 100;
            $currentYearAttackMomentumRateParcours = $currentYearParcoursResults->zip($cyParcoursWeights)
                ->map(function ($p) use ($secondPlaceGapMap) {
                    $result = $p[0];
                    $weight = $p[1];

                    return $this->attackMomentumSignal($result, $secondPlaceGapMap) * $weight;
                })
                ->sum() / $cyParcoursWeights->sum() * 100;
        } else {
            $currentYearAvgPositionParcours = null;
            $currentYearTop10RateParcours = null;
            $currentYearCloseFinishRateParcours = null;
            $currentYearAttackMomentumRateParcours = null;
        }
        $currentYearStageSubtypeResults = $currentYearResults->filter(
            fn($r) => in_array(($r->context_stage_subtype ?? null), $relatedStageSubtypes, true)
        );
        if ($currentYearStageSubtypeResults->isNotEmpty()) {
            $cyStageSubtypeWeights = $weights->only($currentYearStageSubtypeResults->keys());
            $currentYearAvgPositionStageSubtype = $currentYearStageSubtypeResults->zip($cyStageSubtypeWeights)
                ->map(fn($p) => $p[0]->position * $p[1])
                ->sum() / $cyStageSubtypeWeights->sum();
            $currentYearTop10RateStageSubtype = $currentYearStageSubtypeResults->zip($cyStageSubtypeWeights)
                ->map(fn($p) => ($p[0]->position <= 10 ? 1 : 0) * $p[1])
                ->sum() / $cyStageSubtypeWeights->sum() * 100;
        } else {
            $currentYearAvgPositionStageSubtype = null;
            $currentYearTop10RateStageSubtype = null;
        }

        $parcoursResultsCount = $parcoursGroup->count();
        $stageSubtypeResultsCount = $stageSubtypeGroup->count();

        // Specialisatieratio: hoe véél beter is de renner op déze koers vs. algemeen?
        // Van der Poel bij RVV: 8.51 / 1.83 = 4.65 (sterke specialist)
        // Evenepoel bij RVV:    13   / 13   = 1.00 (geen specialisme)
        $raceSpecificityRatio = $avgThisRace > 0 ? round($avgPos / $avgThisRace, 3) : 1.0;

        // Leeftijd: exacte geboortedatum of schatting
        $age = null;
        if ($rider->date_of_birth) {
            $age = $rider->date_of_birth->diffInYears($race->start_date);
        } elseif ($rider->age_approx) {
            $age = $rider->age_approx;
        }

        $stageProfiles = $this->computeStageProfiles($prior, $currentYear);

        return [
            'rider_slug'              => $rider->pcs_slug,
            'prediction_type'         => $predictionType,
            'stage_number'            => $stageNumber,
            'field_size'              => $fieldSize,
            'race_days'               => $raceDays,
            'category_weight'         => $categoryWeight,
            'prediction_type_code'    => $predictionTypeCode,
            'stage_subtype'           => $contextStageSubtype,
            'stage_subtype_code'      => $stageSubtypeCode,
            'field_pct_career_points' => 0.5,
            'field_pct_pcs_ranking'   => 0.5,
            'field_pct_uci_ranking'   => 0.5,
            'field_pct_recent_form'   => 0.5,
            'field_pct_season_form'   => 0.5,
            'field_pct_course_fit'    => 0.5,
            'field_pct_top10_rate'    => 0.5,
            'favourite_score'         => 50.0,
            'specialist_score'        => 50.0,
            'season_dominance_score'  => 50.0,
            'avg_position'            => round($avgPos, 2),
            'avg_position_parcours'   => round($avgPosParcours, 2),
            'avg_position_stage_subtype' => round($avgPosStageSubtype, 2),
            'recent_avg_position_parcours' => round($recentParcoursAvg, 2),
            'recent_avg_position_stage_subtype' => round($recentStageSubtypeAvg, 2),
            'recent_top10_rate_parcours' => round($recentParcoursTop10Rate, 2),
            'recent_top10_rate_stage_subtype' => round($recentStageSubtypeTop10Rate, 2),
            'top10_rate'              => round($top10Rate, 2),
            'form_trend'              => round($formTrend, 2),
            'recent_avg_position'     => round($recentAvg, 2),
            'recent_top10_rate'       => round($recentTop10Rate, 2),
            'top10_last_10_rate'      => round($top10Last10Rate, 2),
            'recency_weighted_avg_position_10' => round($recencyWeightedAvgPosition10, 2),
            'avg_position_this_race'  => round($avgThisRace, 2),
            'best_result_this_race'   => $bestResultThisRace,
            'wins_this_race'          => $winsThisRace,
            'podiums_this_race'       => $podiumsThisRace,
            'current_year_avg_position' => $currentYearAvgPosition !== null ? round($currentYearAvgPosition, 2) : null,
            'current_year_top10_rate' => $currentYearTop10Rate !== null ? round($currentYearTop10Rate, 2) : null,
            'current_year_close_finish_rate' => $currentYearCloseFinishRate !== null ? round($currentYearCloseFinishRate, 2) : null,
            'current_year_attack_momentum_rate' => $currentYearAttackMomentumRate !== null ? round($currentYearAttackMomentumRate, 2) : null,
            'current_year_avg_position_parcours' => $currentYearAvgPositionParcours !== null ? round($currentYearAvgPositionParcours, 2) : null,
            'current_year_top10_rate_parcours' => $currentYearTop10RateParcours !== null ? round($currentYearTop10RateParcours, 2) : null,
            'current_year_close_finish_rate_parcours' => $currentYearCloseFinishRateParcours !== null ? round($currentYearCloseFinishRateParcours, 2) : null,
            'current_year_attack_momentum_rate_parcours' => $currentYearAttackMomentumRateParcours !== null ? round($currentYearAttackMomentumRateParcours, 2) : null,
            'current_year_avg_position_stage_subtype' => $currentYearAvgPositionStageSubtype !== null ? round($currentYearAvgPositionStageSubtype, 2) : null,
            'current_year_top10_rate_stage_subtype' => $currentYearTop10RateStageSubtype !== null ? round($currentYearTop10RateStageSubtype, 2) : null,
            'sprint_profile_score' => $stageProfiles['sprint']['score'],
            'punch_profile_score' => $stageProfiles['punch']['score'],
            'climb_profile_score' => $stageProfiles['climb']['score'],
            'tt_profile_score' => $stageProfiles['tt']['score'],
            'sprint_profile_experience' => $stageProfiles['sprint']['experience'],
            'punch_profile_experience' => $stageProfiles['punch']['experience'],
            'climb_profile_experience' => $stageProfiles['climb']['experience'],
            'tt_profile_experience' => $stageProfiles['tt']['experience'],
            'pcs_speciality_one_day' => $rider->pcs_speciality_one_day,
            'pcs_speciality_gc' => $rider->pcs_speciality_gc,
            'pcs_speciality_tt' => $rider->pcs_speciality_tt,
            'pcs_speciality_sprint' => $rider->pcs_speciality_sprint,
            'pcs_speciality_climber' => $rider->pcs_speciality_climber,
            'pcs_speciality_hills' => $rider->pcs_speciality_hills,
            'wins_current_year'       => $winsCurrentYear,
            'podiums_current_year'    => $podiumsCurrentYear,
            'current_year_results_count' => $currentYearResultsCount,
            'parcours_results_count'  => $parcoursResultsCount,
            'stage_subtype_results_count' => $stageSubtypeResultsCount,
            'this_race_results_count' => $thisRaceResultsCount,
            'race_specificity_ratio'  => $raceSpecificityRatio,
            'pcs_top_competitor_rank' => $pcsTopCompetitor['rank'] ?? null,
            'pcs_top_competitor_points' => $pcsTopCompetitor['pcs_points'] ?? null,
            'pcs_top_competitor_pcs_ranking' => $pcsTopCompetitor['pcs_ranking'] ?? null,
            'pcs_recent_activity_count_30d' => $pcsSeasonSignals['recent_activity_count_30d'],
            'pcs_season_finished_count' => $pcsSeasonSignals['season_finished_count'],
            'pcs_season_top10_rate' => $pcsSeasonSignals['season_top10_rate'],
            'pcs_small_race_wins' => $pcsSeasonSignals['small_race_wins'],
            'pcs_small_race_top10_rate' => $pcsSeasonSignals['small_race_top10_rate'],
            'pcs_recent_nonfinish_count_90d' => $pcsSeasonSignals['recent_nonfinish_count_90d'],
            'pcs_last_incident_days_ago' => $pcsSeasonSignals['last_incident_days_ago'],
            'pcs_comeback_finished_count' => $pcsSeasonSignals['comeback_finished_count'],
            'pcs_days_since_last_result' => $pcsSeasonSignals['days_since_last_result'],
            'team_startlist_size' => $teamSignals['team_startlist_size'],
            'team_career_points_total' => $teamSignals['team_career_points_total'],
            'team_career_points_share' => $teamSignals['team_career_points_share'],
            'manual_incident_penalty' => round($combinedIncidentPenalty, 4),
            'manual_incident_days_ago' => $combinedIncidentDaysAgo,
            'race_dynamics_form_adjustment' => round((float) $raceDynamics['form_adjustment'], 4),
            'race_dynamics_incident_penalty' => round((float) $raceDynamics['incident_penalty'], 4),
            'live_stage_results_count' => $liveStageResultsCount,
            'live_stage_avg_position' => $liveStageAveragePosition,
            'live_stage_best_position' => $liveStageBestPosition,
            'live_stage_top10_count' => $liveStageTop10Count,
            'live_classification_position' => $liveClassificationPosition,
            'recent_one_day_position' => $recentOneDayPosition,
            'recent_one_day_days_ago' => $recentOneDayDaysAgo,
            'recent_one_day_momentum' => $recentOneDayMomentum,
            'career_points'           => $rider->career_points,
            'pcs_ranking'             => $rider->pcs_ranking,
            'uci_ranking'             => $rider->uci_ranking,
            'age'                     => $age,
            'n_results'               => $contextPrior->count(),
        ];
    }

    private function buildPredictionContexts(Race $race): Collection
    {
        if ($race->isOneDay()) {
            return collect([[
                'prediction_type' => 'result',
                'stage_number'    => 0,
                'parcours_type'   => $race->parcours_type,
            ]]);
        }

        $contexts = collect($race->stages_json ?? [])
            ->map(function (array $stage) use ($race) {
                return [
                    'prediction_type' => 'stage',
                    'stage_number'    => (int) ($stage['number'] ?? 0),
                    'parcours_type'   => $stage['parcours_type'] ?? $race->parcours_type,
                    'stage_subtype'   => $stage['stage_subtype'] ?? $this->defaultStageSubtypeForParcours($stage['parcours_type'] ?? $race->parcours_type),
                ];
            })
            ->filter(fn(array $context) => $context['stage_number'] > 0)
            ->values();

        return $contexts
            ->concat(collect([
                [
                    'prediction_type' => 'gc',
                    'stage_number'    => 0,
                    'parcours_type'   => $race->parcours_type,
                ],
                [
                    'prediction_type' => 'points',
                    'stage_number'    => 0,
                    'parcours_type'   => 'flat',
                ],
                [
                    'prediction_type' => 'kom',
                    'stage_number'    => 0,
                    'parcours_type'   => 'mountain',
                ],
                [
                    'prediction_type' => 'youth',
                    'stage_number'    => 0,
                    'parcours_type'   => $race->parcours_type,
                ],
            ]))
            ->values();
    }

    private function isEligibleForPredictionContext(Rider $rider, Race $race, array $context): bool
    {
        $predictionType = $context['prediction_type'] ?? 'result';

        if ($predictionType !== 'youth') {
            return true;
        }

        $ageAtRaceStart = null;

        if ($rider->date_of_birth) {
            $ageAtRaceStart = $rider->date_of_birth->diffInYears($race->start_date);
        } elseif ($rider->age_approx !== null) {
            $ageAtRaceStart = (int) $rider->age_approx;
        }

        return $ageAtRaceStart === null || $ageAtRaceStart < 26;
    }

    private function historyResultTypes(
        string $predictionType,
        ?string $contextParcoursType = null,
        ?string $contextStageSubtype = null
    ): array
    {
        if ($predictionType === 'stage') {
            $stageTypes = match ($contextStageSubtype) {
                'sprint' => ['stage', 'result', 'points'],
                'reduced_sprint' => ['stage', 'result', 'points', 'gc', 'youth'],
                'summit_finish' => ['stage', 'gc', 'kom', 'youth', 'result'],
                'high_mountain' => ['stage', 'gc', 'kom', 'youth', 'result'],
                'tt', 'ttt' => ['stage', 'gc', 'result'],
                default => null,
            };

            if ($stageTypes !== null) {
                return $stageTypes;
            }

            return match ($contextParcoursType) {
                'mountain' => ['stage', 'gc', 'kom', 'youth', 'result'],
                'hilly' => ['stage', 'result', 'gc', 'youth'],
                'flat' => ['stage', 'result', 'points'],
                'tt' => ['stage', 'gc', 'result'],
                default => ['stage', 'result', 'gc'],
            };
        }

        return match ($predictionType) {
            'result' => ['result', 'stage'],
            'gc'     => ['gc', 'youth', 'stage'],
            'points' => ['points', 'result', 'stage'],
            'kom'    => ['kom', 'stage', 'gc'],
            'youth'  => ['youth', 'gc', 'stage'],
            default  => ['result', 'stage', 'gc'],
        };
    }

    private function fallbackHistoryResultTypes(): array
    {
        return ['result', 'stage', 'gc', 'points', 'kom', 'youth'];
    }

    private function resolveResultParcoursType(string $resultType, ?string $raceParcoursType, mixed $stagesJson, int $stageNumber = 0): string
    {
        if ($resultType === 'stage') {
            foreach ($this->decodeStagesJson($stagesJson) as $stage) {
                if ((int) ($stage['number'] ?? 0) === $stageNumber) {
                    return $stage['parcours_type'] ?? ($raceParcoursType ?: 'default');
                }
            }
        }

        return match ($resultType) {
            'points' => 'flat',
            'kom'    => 'mountain',
            default  => $raceParcoursType ?: 'default',
        };
    }

    private function resolveResultStageSubtype(string $resultType, ?string $raceParcoursType, mixed $stagesJson, int $stageNumber = 0): string
    {
        if ($resultType === 'stage') {
            foreach ($this->decodeStagesJson($stagesJson) as $stage) {
                if ((int) ($stage['number'] ?? 0) === $stageNumber) {
                    return $stage['stage_subtype'] ?? $this->defaultStageSubtypeForParcours($stage['parcours_type'] ?? $raceParcoursType);
                }
            }
        }

        return match ($resultType) {
            'points' => 'sprint',
            'kom'    => 'high_mountain',
            default  => $this->defaultStageSubtypeForParcours($raceParcoursType),
        };
    }

    private function decodeStagesJson(mixed $stagesJson): array
    {
        if (is_array($stagesJson)) {
            return $stagesJson;
        }

        if (!is_string($stagesJson) || trim($stagesJson) === '') {
            return [];
        }

        $decoded = json_decode($stagesJson, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function categoryWeight(?string $category): float
    {
        $value = strtolower($category ?? '');

        if (str_contains($value, 'grand tour')) {
            return 1.35;
        }

        if (str_contains($value, 'uwt') || str_contains($value, 'worldtour')) {
            return 1.25;
        }

        if (str_contains($value, '.pro') || str_contains($value, 'proseries')) {
            return 1.05;
        }

        if (str_contains($value, 'hc')) {
            return 0.95;
        }

        if (preg_match('/^[12]\./', $value) === 1) {
            return 0.82;
        }

        return 0.90;
    }

    private function predictionTypeCode(string $predictionType): float
    {
        return match ($predictionType) {
            'stage'  => 1.0,
            'gc'     => 2.0,
            'points' => 3.0,
            'kom'    => 4.0,
            'youth'  => 5.0,
            default  => 0.0,
        };
    }

    private function stageSubtypeCode(?string $stageSubtype): float
    {
        return match ($stageSubtype) {
            'sprint'         => 1.0,
            'reduced_sprint' => 2.0,
            'summit_finish'  => 3.0,
            'high_mountain'  => 4.0,
            'tt'             => 5.0,
            'ttt'            => 6.0,
            default          => 0.0,
        };
    }

    private function defaultStageSubtypeForParcours(?string $parcoursType): string
    {
        return match ($parcoursType) {
            'flat'     => 'sprint',
            'hilly'    => 'reduced_sprint',
            'mountain' => 'summit_finish',
            'tt'       => 'tt',
            default    => 'mixed',
        };
    }

    private function isCloseFinishResult(int|float|null $position, int|float|null $gapSeconds): bool
    {
        if ($position === null) {
            return false;
        }

        if ($gapSeconds !== null) {
            return $gapSeconds <= 20;
        }

        // Sommige uitslagen hebben geen expliciete gap in de bron.
        // Top-30 zonder gemelde gap behandelen we als "mee in de eerste groep".
        return $position <= 30;
    }

    private function secondPlaceGapMap(Collection $results): array
    {
        $raceIds = $results->pluck('race_id')->filter()->unique()->values();
        if ($raceIds->isEmpty()) {
            return [];
        }

        return RaceResult::query()
            ->whereIn('race_id', $raceIds)
            ->where('status', 'finished')
            ->where('position', 2)
            ->whereNotNull('gap_seconds')
            ->get(['race_id', 'result_type', 'stage_number', 'gap_seconds'])
            ->mapWithKeys(function ($row) {
                $key = $this->resultContextKey(
                    (int) $row->race_id,
                    (string) $row->result_type,
                    (int) ($row->stage_number ?? 0),
                );

                return [$key => (int) $row->gap_seconds];
            })
            ->all();
    }

    private function attackMomentumSignal(object $result, array $secondPlaceGapMap): float
    {
        $position = isset($result->position) && is_numeric($result->position) ? (int) $result->position : null;
        if ($position === null || $position > 15) {
            return 0.0;
        }

        $gap = isset($result->gap_seconds) && is_numeric($result->gap_seconds) ? (int) $result->gap_seconds : null;
        $stageNumber = isset($result->stage_number) && is_numeric($result->stage_number) ? (int) $result->stage_number : 0;
        $resultType = (string) ($result->result_type ?? 'result');
        $raceId = isset($result->race_id) && is_numeric($result->race_id) ? (int) $result->race_id : 0;

        if ($position === 1) {
            $winnerMargin = $gap;
            if ($raceId > 0) {
                $key = $this->resultContextKey($raceId, $resultType, $stageNumber);
                $winnerMargin = $secondPlaceGapMap[$key] ?? $winnerMargin;
            }

            if ($winnerMargin !== null) {
                if ($winnerMargin >= 20) return 1.0;
                if ($winnerMargin >= 8) return 0.85;
                if ($winnerMargin >= 3) return 0.65;
                return 0.55;
            }

            return 0.45;
        }

        if ($position <= 3) {
            if ($gap !== null) {
                if ($gap <= 20) return 0.65;
                if ($gap <= 45) return 0.35;
                return 0.10;
            }

            // Sommige bronnen geven geen gap voor podiumplaatsen.
            // Behandel podium zonder gap als sterk koerssignaal.
            return 0.55;
        }

        if ($position <= 10) {
            if ($gap !== null) {
                if ($gap <= 20) return 0.35;
                if ($gap <= 45) return 0.15;
            }
            return 0.10;
        }

        return 0.0;
    }

    private function resultContextKey(int $raceId, string $resultType, int $stageNumber): string
    {
        return "{$raceId}|{$resultType}|{$stageNumber}";
    }

    private function relatedStageSubtypes(?string $stageSubtype): array
    {
        return match ($stageSubtype) {
            'sprint' => ['sprint', 'reduced_sprint'],
            'reduced_sprint' => ['reduced_sprint', 'sprint'],
            'summit_finish' => ['summit_finish', 'high_mountain'],
            'high_mountain' => ['high_mountain', 'summit_finish'],
            'tt' => ['tt', 'ttt'],
            'ttt' => ['ttt', 'tt'],
            default => [$stageSubtype ?: 'mixed'],
        };
    }

    private function computeStageProfiles(Collection $prior, int $currentYear): array
    {
        $definitions = [
            'sprint' => [
                'subtypes' => ['sprint', 'reduced_sprint'],
                'parcours' => ['flat'],
                'result_types' => ['stage', 'points'],
            ],
            'punch' => [
                'subtypes' => ['reduced_sprint', 'summit_finish'],
                'parcours' => ['hilly'],
                'result_types' => ['stage'],
            ],
            'climb' => [
                'subtypes' => ['summit_finish', 'high_mountain'],
                'parcours' => ['mountain'],
                'result_types' => ['stage'],
            ],
            'tt' => [
                'subtypes' => ['tt', 'ttt'],
                'parcours' => ['tt'],
                'result_types' => ['stage'],
            ],
        ];

        $profiles = [];

        foreach ($definitions as $name => $definition) {
            $relevant = $prior->filter(function ($result) use ($definition) {
                $matchesType = in_array($result->result_type, $definition['result_types'], true);
                if (!$matchesType) {
                    return false;
                }

                if ($result->result_type === 'stage') {
                    return in_array(($result->context_stage_subtype ?? null), $definition['subtypes'], true);
                }

                return in_array(($result->context_parcours_type ?? null), $definition['parcours'], true);
            })->values();

            $profiles[$name] = $this->stageProfileSummary($relevant, $currentYear);
        }

        return $profiles;
    }

    private function stageProfileSummary(Collection $results, int $currentYear): array
    {
        if ($results->isEmpty()) {
            return ['score' => 25.0, 'experience' => 0.0];
        }

        $weights = $results->map(function ($result) use ($currentYear) {
            $yearsAgo = max(0, $currentYear - (int) $result->race_year);

            return match (true) {
                $yearsAgo === 0 => self::CURRENT_YEAR_BOOST,
                $yearsAgo === 1 => 1.0,
                default => pow(self::DECAY, $yearsAgo - 1),
            };
        })->values();

        $weightSum = max(0.0001, $weights->sum());
        $avgPosition = $results->zip($weights)->map(fn($pair) => $pair[0]->position * $pair[1])->sum() / $weightSum;
        $recent = $results->take(-5)->values();
        $recentAvg = $recent->isNotEmpty() ? $recent->avg('position') : $avgPosition;
        $recentTop10 = $recent->isNotEmpty()
            ? $recent->filter(fn($result) => $result->position <= 10)->count() / $recent->count() * 100
            : 0.0;

        $currentYearResults = $results->filter(fn($result) => (int) $result->race_year === $currentYear);
        if ($currentYearResults->isNotEmpty()) {
            $currentYearWeights = $weights->only($currentYearResults->keys())->values();
            $currentYearWeightSum = max(0.0001, $currentYearWeights->sum());
            $currentYearAvg = $currentYearResults->values()->zip($currentYearWeights)
                ->map(fn($pair) => $pair[0]->position * $pair[1])
                ->sum() / $currentYearWeightSum;
            $currentYearTop10 = $currentYearResults->values()->zip($currentYearWeights)
                ->map(fn($pair) => ($pair[0]->position <= 10 ? 1 : 0) * $pair[1])
                ->sum() / $currentYearWeightSum * 100;
        } else {
            $currentYearAvg = null;
            $currentYearTop10 = 0.0;
        }

        $weightedRate = function (Collection $subset, Collection $subsetWeights, int $limit): float {
            if ($subset->isEmpty()) {
                return 0.0;
            }

            $weightSum = max(0.0001, $subsetWeights->sum());

            return $subset->zip($subsetWeights)
                ->map(fn($pair) => ($pair[0]->position <= $limit ? 1 : 0) * $pair[1])
                ->sum() / $weightSum * 100;
        };

        $podiumRate = $weightedRate($results->values(), $weights, 3);
        $winRate = $weightedRate($results->values(), $weights, 1);
        $top5Rate = $weightedRate($results->values(), $weights, 5);

        $recentPodium = $weightedRate($recent->values(), collect(array_fill(0, $recent->count(), 1.0)), 3);
        $recentWin = $weightedRate($recent->values(), collect(array_fill(0, $recent->count(), 1.0)), 1);

        if ($currentYearResults->isNotEmpty()) {
            $currentYearValues = $currentYearResults->values();
            $currentYearPodium = $weightedRate($currentYearValues, $currentYearWeights, 3);
            $currentYearWin = $weightedRate($currentYearValues, $currentYearWeights, 1);
        } else {
            $currentYearPodium = 0.0;
            $currentYearWin = 0.0;
        }

        $score =
            $this->normalizedMetric($avgPosition, 25.0, true) * 18.0 +
            $this->normalizedMetric($recentAvg, 25.0, true) * 12.0 +
            $this->normalizedMetric($currentYearAvg, 25.0, true) * 12.0 +
            min(1.0, max(0.0, $top5Rate / 100.0)) * 14.0 +
            min(1.0, max(0.0, $podiumRate / 100.0)) * 16.0 +
            min(1.0, max(0.0, $winRate / 100.0)) * 12.0 +
            min(1.0, max(0.0, $recentTop10 / 100.0)) * 6.0 +
            min(1.0, max(0.0, $recentPodium / 100.0)) * 4.0 +
            min(1.0, max(0.0, $recentWin / 100.0)) * 2.0 +
            min(1.0, max(0.0, $currentYearTop10 / 100.0)) * 6.0 +
            min(1.0, max(0.0, $currentYearPodium / 100.0)) * 6.0 +
            min(1.0, max(0.0, $currentYearWin / 100.0)) * 6.0 +
            min(1.0, $results->count() / 18.0) * 6.0;

        return [
            'score' => round(min(100.0, $score), 2),
            'experience' => round(min(1.0, $results->count() / 18.0), 4),
        ];
    }

    private function applyFieldRelativeFeatures(array $payload): array
    {
        $configs = [
            ['source' => 'career_points', 'target' => 'field_pct_career_points', 'inverse' => false],
            ['source' => 'pcs_ranking', 'target' => 'field_pct_pcs_ranking', 'inverse' => true],
            ['source' => 'uci_ranking', 'target' => 'field_pct_uci_ranking', 'inverse' => true],
            ['source' => 'recent_avg_position', 'target' => 'field_pct_recent_form', 'inverse' => true],
            ['source' => 'current_year_avg_position', 'target' => 'field_pct_season_form', 'inverse' => true],
            ['source' => 'recent_top10_rate', 'target' => 'field_pct_top10_rate', 'inverse' => false],
        ];

        foreach ($configs as $config) {
            $scores = $this->percentileScores(
                array_map(fn(array $row) => $row[$config['source']] ?? null, $payload),
                $config['inverse'],
            );

            foreach ($payload as $index => $row) {
                $payload[$index][$config['target']] = $scores[$index] ?? 0.5;
            }
        }

        $courseFitScores = $this->percentileScores(
            array_map(fn(array $row) => $this->effectiveCourseFitMetric($row), $payload),
            true,
        );

        foreach ($payload as $index => $row) {
            $payload[$index]['field_pct_course_fit'] = $courseFitScores[$index] ?? 0.5;
        }

        return $payload;
    }

    private function applyCompositeFeatures(array $payload): array
    {
        foreach ($payload as $index => $row) {
            $predictionType = $row['prediction_type'] ?? 'result';
            $careerPct = (float) ($row['field_pct_career_points'] ?? 0.5);
            $pcsPct = (float) ($row['field_pct_pcs_ranking'] ?? 0.5);
            $uciPct = (float) ($row['field_pct_uci_ranking'] ?? 0.5);
            $recentPct = (float) ($row['field_pct_recent_form'] ?? 0.5);
            $seasonPct = (float) ($row['field_pct_season_form'] ?? 0.5);
            $coursePct = (float) ($row['field_pct_course_fit'] ?? 0.5);
            $top10Pct = (float) ($row['field_pct_top10_rate'] ?? 0.5);
            $recentParcoursAvg = $this->normalizedMetric($row['recent_avg_position_parcours'] ?? null, 25.0, true);
            $recentParcoursTop10 = min(1.0, max(0.0, (float) ($row['recent_top10_rate_parcours'] ?? 0) / 100.0));
            $currentYearParcoursAvg = $this->normalizedMetricOrNeutral($row['current_year_avg_position_parcours'] ?? null, 25.0, true);
            $currentYearParcoursTop10 = min(1.0, max(0.0, (float) ($row['current_year_top10_rate_parcours'] ?? 0) / 100.0));
            $stageSubtypeAvg = $this->normalizedMetric($row['avg_position_stage_subtype'] ?? null, 25.0, true);
            $recentStageSubtypeAvg = $this->normalizedMetric($row['recent_avg_position_stage_subtype'] ?? null, 25.0, true);
            $currentYearStageSubtypeAvg = $this->normalizedMetricOrNeutral($row['current_year_avg_position_stage_subtype'] ?? null, 25.0, true);
            $recentStageSubtypeTop10 = min(1.0, max(0.0, (float) ($row['recent_top10_rate_stage_subtype'] ?? 0) / 100.0));
            $currentYearStageSubtypeTop10 = min(1.0, max(0.0, (float) ($row['current_year_top10_rate_stage_subtype'] ?? 0) / 100.0));
            $stageSubtypeExperience = min(1.0, (float) ($row['stage_subtype_results_count'] ?? 0) / 10.0);
            $stageProfileFit = $this->stageProfileFitScore($row);
            $stageProfileExperience = $this->stageProfileFitExperience($row);
            $currentYearCloseFinish = min(1.0, max(0.0, (float) ($row['current_year_close_finish_rate'] ?? 0) / 100.0));
            $currentYearAttackMomentum = min(1.0, max(0.0, (float) ($row['current_year_attack_momentum_rate'] ?? 0) / 100.0));
            $currentYearCloseFinishParcours = min(1.0, max(0.0, (float) ($row['current_year_close_finish_rate_parcours'] ?? 0) / 100.0));
            $currentYearAttackMomentumParcours = min(1.0, max(0.0, (float) ($row['current_year_attack_momentum_rate_parcours'] ?? 0) / 100.0));
            $scenarioFormSignal = ($currentYearAttackMomentum * 0.6) + ($currentYearCloseFinish * 0.4);
            $parcoursScenarioFormSignal = ($currentYearAttackMomentumParcours * 0.7) + ($currentYearCloseFinishParcours * 0.3);
            $recentOneDayMomentum = min(1.0, max(0.0, (float) ($row['recent_one_day_momentum'] ?? 0.0)));
            $winsThisRace = (float) ($row['wins_this_race'] ?? 0);
            $podiumsThisRace = (float) ($row['podiums_this_race'] ?? 0);
            $winsCurrentYear = (float) ($row['wins_current_year'] ?? 0);
            $podiumsCurrentYear = (float) ($row['podiums_current_year'] ?? 0);
            $currentYearAvg = $this->normalizedMetricOrNeutral($row['current_year_avg_position'] ?? null, 25.0, true);
            $recentAvg = $this->normalizedMetric($row['recent_avg_position'] ?? null, 25.0, true);
            $parcoursAvg = $this->normalizedMetric($row['avg_position_parcours'] ?? null, 25.0, true);
            $raceSpecificity = min(1.0, max(0.0, ((float) ($row['race_specificity_ratio'] ?? 1.0) - 1.0) / 3.0));
            $parcoursExperience = min(1.0, (float) ($row['parcours_results_count'] ?? 0) / 12.0);
            $raceExperience = min(1.0, (float) ($row['this_race_results_count'] ?? 0) / 5.0);
            $seasonWinsPct = min(1.0, $winsCurrentYear / 6.0);
            $seasonPodiumsPct = min(1.0, $podiumsCurrentYear / 10.0);
            $raceWinsPct = min(1.0, $winsThisRace / 3.0);
            $racePodiumsPct = min(1.0, $podiumsThisRace / 5.0);
            $classificationRaceHistorySignal = ($raceWinsPct * 0.65 + $racePodiumsPct * 0.35)
                * (0.20 + $raceExperience * 0.80);

            if ($predictionType === 'stage') {
                $favouriteScore = (
                    $careerPct * 12.0 +
                    $pcsPct * 10.0 +
                    $uciPct * 6.0 +
                    $seasonPct * 12.0 +
                    $recentPct * 8.0 +
                    $coursePct * 8.0 +
                    $top10Pct * 6.0 +
                    $currentYearParcoursAvg * 6.0 +
                    $currentYearParcoursTop10 * 6.0 +
                    $currentYearStageSubtypeAvg * 12.0 +
                    $currentYearStageSubtypeTop10 * 10.0 +
                    $recentStageSubtypeAvg * 8.0 +
                    $recentStageSubtypeTop10 * 8.0 +
                    $stageProfileFit * 12.0 +
                    $stageProfileExperience * 6.0 +
                    $stageSubtypeExperience * 8.0 +
                    $seasonWinsPct * 5.0 +
                    $raceWinsPct * 3.0
                );

                $specialistScore = (
                    $coursePct * 14.0 +
                    $top10Pct * 8.0 +
                    $parcoursAvg * 8.0 +
                    $recentParcoursAvg * 6.0 +
                    $recentParcoursTop10 * 6.0 +
                    $stageSubtypeAvg * 18.0 +
                    $recentStageSubtypeAvg * 14.0 +
                    $currentYearStageSubtypeAvg * 16.0 +
                    $recentStageSubtypeTop10 * 10.0 +
                    $currentYearStageSubtypeTop10 * 10.0 +
                    $stageProfileFit * 16.0 +
                    $stageProfileExperience * 8.0 +
                    $stageSubtypeExperience * 10.0 +
                    $raceSpecificity * 4.0 +
                    $raceWinsPct * 4.0 +
                    $racePodiumsPct * 4.0
                );

                $seasonDominanceScore = (
                    $seasonPct * 22.0 +
                    $recentPct * 16.0 +
                    $currentYearAvg * 10.0 +
                    $recentAvg * 8.0 +
                    $currentYearParcoursAvg * 6.0 +
                    $recentParcoursAvg * 4.0 +
                    $currentYearStageSubtypeAvg * 12.0 +
                    $recentStageSubtypeAvg * 8.0 +
                    $currentYearStageSubtypeTop10 * 8.0 +
                    $recentStageSubtypeTop10 * 6.0 +
                    $stageProfileFit * 10.0 +
                    $stageProfileExperience * 6.0 +
                    $stageSubtypeExperience * 6.0 +
                    $seasonWinsPct * 10.0 +
                    $seasonPodiumsPct * 6.0 +
                    $careerPct * 4.0
                );
            } elseif (in_array($predictionType, ['gc', 'youth'], true)) {
                $favouriteScore = (
                    $careerPct * 14.0 +
                    $pcsPct * 12.0 +
                    $uciPct * 6.0 +
                    $seasonPct * 18.0 +
                    $recentPct * 14.0 +
                    $coursePct * 14.0 +
                    $top10Pct * 4.0 +
                    $currentYearAvg * 12.0 +
                    $recentAvg * 8.0 +
                    $currentYearParcoursAvg * 12.0 +
                    $currentYearParcoursTop10 * 10.0 +
                    $recentParcoursAvg * 8.0 +
                    $recentParcoursTop10 * 6.0 +
                    $seasonWinsPct * 8.0 +
                    $seasonPodiumsPct * 6.0 +
                    $classificationRaceHistorySignal * 8.0
                );

                $specialistScore = (
                    $coursePct * 20.0 +
                    $top10Pct * 6.0 +
                    $parcoursAvg * 18.0 +
                    $recentParcoursAvg * 14.0 +
                    $recentParcoursTop10 * 10.0 +
                    $currentYearParcoursAvg * 14.0 +
                    $currentYearParcoursTop10 * 10.0 +
                    $raceSpecificity * (4.0 + $raceExperience * 4.0) +
                    $classificationRaceHistorySignal * 14.0 +
                    $parcoursExperience * 8.0 +
                    $raceExperience * 4.0
                );

                $seasonDominanceScore = (
                    $seasonPct * 26.0 +
                    $recentPct * 18.0 +
                    $currentYearAvg * 18.0 +
                    $recentAvg * 10.0 +
                    $currentYearParcoursAvg * 12.0 +
                    $recentParcoursAvg * 8.0 +
                    $seasonWinsPct * 12.0 +
                    $seasonPodiumsPct * 8.0 +
                    $careerPct * 6.0
                );
            } elseif ($predictionType === 'points') {
                $favouriteScore = (
                    $careerPct * 10.0 +
                    $pcsPct * 10.0 +
                    $uciPct * 4.0 +
                    $seasonPct * 18.0 +
                    $recentPct * 14.0 +
                    $coursePct * 18.0 +
                    $top10Pct * 10.0 +
                    $currentYearParcoursAvg * 14.0 +
                    $currentYearParcoursTop10 * 12.0 +
                    $recentParcoursAvg * 8.0 +
                    $recentParcoursTop10 * 8.0 +
                    $seasonWinsPct * 8.0 +
                    $classificationRaceHistorySignal * 8.0
                );

                $specialistScore = (
                    $coursePct * 24.0 +
                    $top10Pct * 14.0 +
                    $parcoursAvg * 16.0 +
                    $recentParcoursAvg * 12.0 +
                    $recentParcoursTop10 * 12.0 +
                    $currentYearParcoursAvg * 12.0 +
                    $currentYearParcoursTop10 * 12.0 +
                    $classificationRaceHistorySignal * 10.0 +
                    $parcoursExperience * 8.0
                );

                $seasonDominanceScore = (
                    $seasonPct * 28.0 +
                    $recentPct * 18.0 +
                    $currentYearAvg * 14.0 +
                    $recentAvg * 10.0 +
                    $currentYearParcoursAvg * 12.0 +
                    $recentParcoursAvg * 8.0 +
                    $seasonWinsPct * 12.0 +
                    $seasonPodiumsPct * 8.0 +
                    $top10Pct * 6.0
                );
            } elseif ($predictionType === 'kom') {
                $favouriteScore = (
                    $careerPct * 10.0 +
                    $pcsPct * 8.0 +
                    $uciPct * 4.0 +
                    $seasonPct * 16.0 +
                    $recentPct * 12.0 +
                    $coursePct * 20.0 +
                    $top10Pct * 6.0 +
                    $currentYearAvg * 10.0 +
                    $currentYearParcoursAvg * 16.0 +
                    $currentYearParcoursTop10 * 12.0 +
                    $recentParcoursAvg * 10.0 +
                    $recentParcoursTop10 * 8.0 +
                    $seasonWinsPct * 6.0 +
                    $classificationRaceHistorySignal * 6.0
                );

                $specialistScore = (
                    $coursePct * 26.0 +
                    $parcoursAvg * 18.0 +
                    $recentParcoursAvg * 14.0 +
                    $recentParcoursTop10 * 10.0 +
                    $currentYearParcoursAvg * 14.0 +
                    $currentYearParcoursTop10 * 10.0 +
                    $classificationRaceHistorySignal * 10.0 +
                    $parcoursExperience * 10.0 +
                    $raceExperience * 4.0
                );

                $seasonDominanceScore = (
                    $seasonPct * 24.0 +
                    $recentPct * 16.0 +
                    $currentYearAvg * 14.0 +
                    $recentAvg * 8.0 +
                    $currentYearParcoursAvg * 14.0 +
                    $recentParcoursAvg * 10.0 +
                    $seasonWinsPct * 10.0 +
                    $seasonPodiumsPct * 6.0 +
                    $careerPct * 4.0
                );
            } else {
                $favouriteScore = (
                    $careerPct * 13.0 +
                    $pcsPct * 12.0 +
                    $uciPct * 6.0 +
                    $seasonPct * 20.0 +
                    $recentPct * 16.0 +
                    $coursePct * 12.0 +
                    $top10Pct * 8.0 +
                    $currentYearParcoursAvg * 10.0 +
                    $currentYearParcoursTop10 * 8.0 +
                    $parcoursScenarioFormSignal * 8.0 +
                    $scenarioFormSignal * 4.0 +
                    $recentOneDayMomentum * 10.0 +
                    $seasonWinsPct * 6.0 +
                    $raceWinsPct * 3.0
                );

                $specialistScore = (
                    $coursePct * 22.0 +
                    $top10Pct * 12.0 +
                    $parcoursAvg * 14.0 +
                    $recentParcoursAvg * 10.0 +
                    $recentParcoursTop10 * 10.0 +
                    $parcoursScenarioFormSignal * 12.0 +
                    $scenarioFormSignal * 8.0 +
                    $recentOneDayMomentum * 8.0 +
                    $raceSpecificity * 12.0 +
                    $raceWinsPct * 10.0 +
                    $racePodiumsPct * 8.0 +
                    $parcoursExperience * 6.0 +
                    $raceExperience * 6.0
                );

                $seasonDominanceScore = (
                    $seasonPct * 24.0 +
                    $recentPct * 22.0 +
                    $currentYearAvg * 14.0 +
                    $recentAvg * 10.0 +
                    $currentYearParcoursAvg * 10.0 +
                    $recentParcoursAvg * 6.0 +
                    $parcoursScenarioFormSignal * 8.0 +
                    $scenarioFormSignal * 6.0 +
                    $recentOneDayMomentum * 10.0 +
                    $seasonWinsPct * 14.0 +
                    $seasonPodiumsPct * 8.0 +
                    $careerPct * 6.0
                );
            }

            $payload[$index]['favourite_score'] = round(min(100.0, $favouriteScore), 4);
            $payload[$index]['specialist_score'] = round(min(100.0, $specialistScore), 4);
            $payload[$index]['season_dominance_score'] = round(min(100.0, $seasonDominanceScore), 4);
        }

        return $payload;
    }

    private function effectiveCourseFitMetric(array $row): ?float
    {
        if (($row['prediction_type'] ?? null) !== 'stage') {
            return $this->firstNumeric(
                $row['avg_position_this_race'] ?? null,
                $row['current_year_avg_position_parcours'] ?? null,
                $row['recent_avg_position_parcours'] ?? null,
                $row['avg_position_parcours'] ?? null,
                $row['current_year_avg_position'] ?? null,
                $row['recent_avg_position'] ?? null,
            );
        }

        $weighted = $this->weightedAverage([
            ['value' => $row['current_year_avg_position_stage_subtype'] ?? null, 'weight' => 0.34],
            ['value' => $row['recent_avg_position_stage_subtype'] ?? null, 'weight' => 0.26],
            ['value' => $row['avg_position_stage_subtype'] ?? null, 'weight' => 0.22],
            ['value' => $row['current_year_avg_position_parcours'] ?? null, 'weight' => 0.10],
            ['value' => $row['recent_avg_position_parcours'] ?? null, 'weight' => 0.05],
            ['value' => $row['avg_position_parcours'] ?? null, 'weight' => 0.03],
        ]);

        return $weighted ?? $this->firstNumeric(
            $row['avg_position_stage_subtype'] ?? null,
            $row['avg_position_parcours'] ?? null,
            $row['avg_position_this_race'] ?? null,
            $row['current_year_avg_position'] ?? null,
            $row['recent_avg_position'] ?? null,
        );
    }

    private function weightedAverage(array $items): ?float
    {
        $weightedSum = 0.0;
        $weightSum = 0.0;

        foreach ($items as $item) {
            $value = $item['value'] ?? null;
            $weight = (float) ($item['weight'] ?? 0);

            if (!is_numeric($value) || $weight <= 0) {
                continue;
            }

            $weightedSum += (float) $value * $weight;
            $weightSum += $weight;
        }

        return $weightSum > 0 ? $weightedSum / $weightSum : null;
    }

    private function stageProfileFitScore(array $row): float
    {
        $subtype = $row['stage_subtype'] ?? 'mixed';
        $sprint = min(1.0, max(0.0, (float) ($row['sprint_profile_score'] ?? 25.0) / 100.0));
        $punch = min(1.0, max(0.0, (float) ($row['punch_profile_score'] ?? 25.0) / 100.0));
        $climb = min(1.0, max(0.0, (float) ($row['climb_profile_score'] ?? 25.0) / 100.0));
        $tt = min(1.0, max(0.0, (float) ($row['tt_profile_score'] ?? 25.0) / 100.0));

        return match ($subtype) {
            'sprint' => $sprint,
            'reduced_sprint' => $sprint * 0.55 + $punch * 0.45,
            'summit_finish' => $climb * 0.70 + $punch * 0.30,
            'high_mountain' => $climb * 0.90 + $punch * 0.10,
            'tt', 'ttt' => $tt,
            default => 0.25,
        };
    }

    private function stageProfileFitExperience(array $row): float
    {
        $subtype = $row['stage_subtype'] ?? 'mixed';
        $sprint = min(1.0, max(0.0, (float) ($row['sprint_profile_experience'] ?? 0.0)));
        $punch = min(1.0, max(0.0, (float) ($row['punch_profile_experience'] ?? 0.0)));
        $climb = min(1.0, max(0.0, (float) ($row['climb_profile_experience'] ?? 0.0)));
        $tt = min(1.0, max(0.0, (float) ($row['tt_profile_experience'] ?? 0.0)));

        return match ($subtype) {
            'sprint' => $sprint,
            'reduced_sprint' => $sprint * 0.55 + $punch * 0.45,
            'summit_finish' => $climb * 0.70 + $punch * 0.30,
            'high_mountain' => $climb * 0.90 + $punch * 0.10,
            'tt', 'ttt' => $tt,
            default => 0.0,
        };
    }

    private function firstNumeric(mixed ...$values): ?float
    {
        foreach ($values as $value) {
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    private function percentileScores(array $values, bool $inverse = false): array
    {
        $prepared = collect($values)->map(function ($value, $index) {
            return [
                'index' => $index,
                'value' => is_numeric($value) ? (float) $value : null,
            ];
        });

        $valid = $prepared->filter(fn(array $item) => $item['value'] !== null)->sortBy('value')->values();
        if ($valid->isEmpty()) {
            return array_fill(0, count($values), 0.5);
        }

        if ($valid->count() === 1) {
            return collect($values)->map(fn() => 0.5)->all();
        }

        $scores = array_fill(0, count($values), 0.5);
        $maxIndex = max(1, $valid->count() - 1);

        foreach ($valid as $rank => $item) {
            $percentile = $rank / $maxIndex;
            $scores[$item['index']] = $inverse ? 1.0 - $percentile : $percentile;
        }

        return $scores;
    }

    private function normalizedMetric(mixed $value, float $fallback, bool $inverse = false): float
    {
        if (!is_numeric($value)) {
            $value = $fallback;
        }

        $normalized = min(1.0, max(0.0, (float) $value / max($fallback, 1.0)));

        return $inverse ? 1.0 - $normalized : $normalized;
    }

    /**
     * Like normalizedMetric(), but missing/invalid values return a neutral score (0.5)
     * instead of behaving as "worst possible". This prevents top riders with missing
     * season/parcours signals from getting artificially pushed down.
     */
    private function normalizedMetricOrNeutral(mixed $value, float $fallback, bool $inverse = false, float $neutral = 0.5): float
    {
        if (!is_numeric($value)) {
            return $neutral;
        }

        $normalized = min(1.0, max(0.0, (float) $value / max($fallback, 1.0)));

        return $inverse ? 1.0 - $normalized : $normalized;
    }

    private function stabilizeCourseHistoryAverage(?float $rawAverage, float $fallback, int $sampleCount, string $predictionType): float
    {
        if ($rawAverage === null) {
            return $fallback;
        }

        $requiredSamples = match ($predictionType) {
            'gc', 'youth', 'points', 'kom' => 5.0,
            'stage' => 3.0,
            default => 2.0,
        };

        $reliability = min(1.0, max(0.0, $sampleCount / $requiredSamples));

        return $fallback + (($rawAverage - $fallback) * $reliability);
    }

    private function getPredictionRiderIds(Race $race): Collection
    {
        // 1. Voorkeur: officiële startlijst (race_entries)
        $fromEntries = $race->entries()->pluck('rider_id');
        if ($fromEntries->isNotEmpty()) {
            Log::info("[Prediction] Startlijst gebruikt: {$fromEntries->count()} renners");
            return $fromEntries->unique()->values();
        }

        // 2. PCS top competitors zijn nuttig als context, maar niet als vervanging
        // van een officiële startlijst. Zonder entries voorspellen we niet.
        $fromTopCompetitors = $this->getTopCompetitorRiderIds($race);
        if ($fromTopCompetitors->isNotEmpty()) {
            Log::info("[Prediction] PCS top competitors gevonden ({$fromTopCompetitors->count()}), maar geen officiële startlijst");
        }

        // 3. Resultaten van de race (als die al beschikbaar zijn)
        $fromResults = $race->results()
            ->whereIn('result_type', ['result', 'gc', 'stage'])
            ->pluck('rider_id')->unique();
        if ($fromResults->isNotEmpty()) {
            Log::info("[Prediction] Resultaten gebruikt als startlijst: {$fromResults->count()} renners");
            return $fromResults;
        }

        Log::info("[Prediction] Geen officiële startlijst beschikbaar voor {$race->name}");
        return collect();
    }

    private function getTopCompetitorRiderIds(Race $race): Collection
    {
        if (!$this->pcsSignalsEnabled()) {
            return collect();
        }

        try {
            $data = $this->api->getTopCompetitors($race->pcs_slug, $race->year);
        } catch (\RuntimeException $e) {
            Log::info("[Prediction] Geen PCS top competitors voor {$race->name}: {$e->getMessage()}");
            return collect();
        }

        return collect($data['riders'] ?? [])
            ->map(function (array $entry) {
                $rider = $this->riderSync->syncFromTopCompetitorEntry($entry);
                return $rider->id;
            })
            ->filter()
            ->unique()
            ->values();
    }

    private function topCompetitorMetadata(Race $race, string $riderSlug): array
    {
        if (!$this->pcsSignalsEnabled()) {
            return [];
        }

        $cacheKey = "{$race->pcs_slug}:{$race->year}";

        if (!array_key_exists($cacheKey, $this->topCompetitorCache)) {
            try {
                $data = $this->api->getTopCompetitors($race->pcs_slug, $race->year);
            } catch (\RuntimeException $e) {
                Log::info("[Prediction] Geen PCS top competitors metadata voor {$race->name}: {$e->getMessage()}");
                $this->topCompetitorCache[$cacheKey] = [];
            }

            if (!array_key_exists($cacheKey, $this->topCompetitorCache)) {
                $this->topCompetitorCache[$cacheKey] = collect($data['riders'] ?? [])
                    ->keyBy('rider_slug')
                    ->map(fn (array $entry) => [
                        'rank' => isset($entry['rank']) ? (int) $entry['rank'] : null,
                        'pcs_points' => isset($entry['pcs_points']) ? (float) $entry['pcs_points'] : null,
                        'pcs_ranking' => isset($entry['pcs_ranking']) ? (float) $entry['pcs_ranking'] : null,
                    ])
                    ->all();
            }
        }

        return $this->topCompetitorCache[$cacheKey][$riderSlug] ?? [];
    }

    private function pcsSeasonSignals(Rider $rider, Race $race, array $pcsTopCompetitor = []): array
    {
        $cacheKey = "{$rider->pcs_slug}:{$race->start_date->toDateString()}";

        if (array_key_exists($cacheKey, $this->pcsSeasonSignalsCache)) {
            return $this->pcsSeasonSignalsCache[$cacheKey];
        }

        $default = [
            'recent_activity_count_30d' => 0,
            'season_finished_count' => 0,
            'season_top10_rate' => null,
            'small_race_wins' => 0,
            'small_race_top10_rate' => null,
            'recent_nonfinish_count_90d' => 0,
            'last_incident_days_ago' => null,
            'comeback_finished_count' => 0,
            'days_since_last_result' => null,
        ];

        if (!$this->pcsSignalsEnabled()) {
            return $this->pcsSeasonSignalsCache[$cacheKey] = $default;
        }

        $topCompetitorRank = isset($pcsTopCompetitor['rank']) ? (int) $pcsTopCompetitor['rank'] : null;
        $shouldFetch = $race->isOneDay()
            && (
                ($topCompetitorRank !== null && $topCompetitorRank <= 20)
                || (($rider->pcs_ranking ?? 9999) <= 40)
                || (($rider->career_points ?? 0) >= 700)
            );

        if (!$shouldFetch) {
            return $this->pcsSeasonSignalsCache[$cacheKey] = $default;
        }

        try {
            $data = $this->api->getRiderResults($rider->pcs_slug, $race->year);
        } catch (\RuntimeException $e) {
            Log::info("[Prediction] Geen PCS seizoensresultaten voor {$rider->pcs_slug}: {$e->getMessage()}");
            return $this->pcsSeasonSignalsCache[$cacheKey] = $default;
        }

        $cutoff = $race->start_date->copy()->startOfDay();
        $results = collect($data['results'] ?? [])
            ->map(function (array $result) {
                try {
                    $date = !empty($result['date']) ? \Carbon\Carbon::parse($result['date'])->startOfDay() : null;
                } catch (\Throwable) {
                    $date = null;
                }

                return [
                    'date' => $date,
                    'status' => $result['status'] ?? 'finished',
                    'rank' => isset($result['rank']) && is_numeric($result['rank']) ? (int) $result['rank'] : null,
                    'race_class' => $result['race_class'] ?? null,
                ];
            })
            ->filter(fn (array $result) => $result['date'] !== null && $result['date']->lt($cutoff))
            ->sortBy('date')
            ->values();

        if ($results->isEmpty()) {
            return $this->pcsSeasonSignalsCache[$cacheKey] = $default;
        }

        $finished = $results->where('status', 'finished')->values();
        $recent30Cutoff = $cutoff->copy()->subDays(30);
        $recent90Cutoff = $cutoff->copy()->subDays(90);
        $recentActivity30d = $results->filter(fn (array $result) => $result['date']->gte($recent30Cutoff))->count();
        $recentNonfinishes = $results
            ->filter(fn (array $result) => $result['status'] !== 'finished' && $result['date']->gte($recent90Cutoff))
            ->values();
        $lastIncident = $results->reverse()->first(fn (array $result) => $result['status'] !== 'finished');
        $lastFinished = $results->last();

        $smallRaces = $finished
            ->filter(fn (array $result) => $this->isSmallerRaceClass($result['race_class'] ?? null))
            ->values();

        $seasonFinishedCount = $finished->count();
        $seasonTop10Rate = $seasonFinishedCount > 0
            ? round($finished->filter(fn (array $result) => ($result['rank'] ?? 999) <= 10)->count() / $seasonFinishedCount * 100, 2)
            : null;

        $smallRaceCount = $smallRaces->count();
        $smallRaceTop10Rate = $smallRaceCount > 0
            ? round($smallRaces->filter(fn (array $result) => ($result['rank'] ?? 999) <= 10)->count() / $smallRaceCount * 100, 2)
            : null;

        $comebackFinishedCount = 0;
        if ($lastIncident !== null) {
            $comebackFinishedCount = $finished->filter(fn (array $result) => $result['date']->gt($lastIncident['date']))->count();
        }

        return $this->pcsSeasonSignalsCache[$cacheKey] = [
            'recent_activity_count_30d' => $recentActivity30d,
            'season_finished_count' => $seasonFinishedCount,
            'season_top10_rate' => $seasonTop10Rate,
            'small_race_wins' => $smallRaces->filter(fn (array $result) => ($result['rank'] ?? 999) === 1)->count(),
            'small_race_top10_rate' => $smallRaceTop10Rate,
            'recent_nonfinish_count_90d' => $recentNonfinishes->count(),
            'last_incident_days_ago' => $lastIncident ? $lastIncident['date']->diffInDays($cutoff) : null,
            'comeback_finished_count' => $comebackFinishedCount,
            'days_since_last_result' => $lastFinished ? $lastFinished['date']->diffInDays($cutoff) : null,
        ];
    }

    private function pcsSignalsEnabled(): bool
    {
        return !(bool) config('prediction.skip_pcs_signals', false);
    }

    private function isSmallerRaceClass(?string $raceClass): bool
    {
        if (!$raceClass) {
            return false;
        }

        $value = strtolower($raceClass);

        return str_contains($value, '.pro')
            || str_contains($value, '.1')
            || str_contains($value, '.2')
            || str_contains($value, 'hc');
    }

    private function manualIncidentSignal(string $riderSlug, \Carbon\CarbonInterface $raceDate): array
    {
        $incidents = config('prediction.manual_incidents', []);
        $incident = $incidents[$riderSlug] ?? null;

        if (!is_array($incident) || empty($incident['date'])) {
            return ['penalty' => 0.0, 'days_ago' => null];
        }

        try {
            $incidentDate = \Carbon\Carbon::parse((string) $incident['date'])->startOfDay();
        } catch (\Throwable) {
            return ['penalty' => 0.0, 'days_ago' => null];
        }

        $daysAgo = $incidentDate->diffInDays($raceDate->copy()->startOfDay(), false);
        if ($daysAgo < 0) {
            return ['penalty' => 0.0, 'days_ago' => null];
        }

        $severity = max(0.0, min(1.0, (float) ($incident['severity'] ?? 0.0)));
        $decayDays = max(1, (int) ($incident['decay_days'] ?? 21));
        $decayFactor = max(0.0, 1.0 - ($daysAgo / $decayDays));
        $penalty = round($severity * $decayFactor, 4);

        if ($penalty <= 0.0) {
            return ['penalty' => 0.0, 'days_ago' => $daysAgo];
        }

        return ['penalty' => $penalty, 'days_ago' => $daysAgo];
    }

    private function manualRaceDynamicsSignal(string $riderSlug, Race $race): array
    {
        $overrides = config('prediction.manual_race_dynamics', []);
        if (!is_array($overrides) || $riderSlug === '' || $race->pcs_slug === '') {
            return ['form_adjustment' => 0.0, 'incident_penalty' => 0.0, 'days_ago' => null];
        }

        $raceKey = "{$race->pcs_slug}:{$race->year}";
        $raceDynamics = $overrides[$raceKey] ?? null;
        if (!is_array($raceDynamics)) {
            return ['form_adjustment' => 0.0, 'incident_penalty' => 0.0, 'days_ago' => null];
        }

        $entry = $raceDynamics[$riderSlug] ?? null;
        if (!is_array($entry)) {
            return ['form_adjustment' => 0.0, 'incident_penalty' => 0.0, 'days_ago' => null];
        }

        try {
            $eventDate = !empty($entry['date'])
                ? \Carbon\Carbon::parse((string) $entry['date'])->startOfDay()
                : $race->start_date->copy()->startOfDay();
        } catch (\Throwable) {
            $eventDate = $race->start_date->copy()->startOfDay();
        }

        $targetDate = $race->start_date->copy()->startOfDay();
        $daysAgo = $eventDate->diffInDays($targetDate, false);
        if ($daysAgo < 0) {
            return ['form_adjustment' => 0.0, 'incident_penalty' => 0.0, 'days_ago' => null];
        }

        $decayDays = max(1, (int) ($entry['decay_days'] ?? 21));
        $decayFactor = max(0.0, 1.0 - ($daysAgo / $decayDays));

        $formAdjustment = max(-1.0, min(1.0, (float) ($entry['form_adjustment'] ?? 0.0))) * $decayFactor;
        $incidentPenalty = max(0.0, min(1.0, (float) ($entry['incident_penalty'] ?? 0.0))) * $decayFactor;

        return [
            'form_adjustment' => round($formAdjustment, 4),
            'incident_penalty' => round($incidentPenalty, 4),
            'days_ago' => $daysAgo,
        ];
    }

    private function applyTeamHierarchyAdjustments(
        array $predictions,
        Race $race,
        Collection $slugToId,
        array $featuresBySlug,
        string $predictionType
    ): array {
        if ($predictionType !== 'result' || count($predictions) < 2 || $slugToId->isEmpty()) {
            return $predictions;
        }

        $ridersBySlug = Rider::query()
            ->whereIn('id', $slugToId->values()->all())
            ->select('id', 'pcs_slug', 'team_id')
            ->get()
            ->keyBy('pcs_slug');
        $startlistOrdersByRiderId = RaceEntry::query()
            ->where('race_id', $race->id)
            ->whereIn('rider_id', $slugToId->values()->all())
            ->pluck('startlist_order', 'rider_id');

        $teamBuckets = [];
        foreach ($predictions as $index => $prediction) {
            $slug = (string) ($prediction['rider_slug'] ?? '');
            if ($slug === '' || !isset($ridersBySlug[$slug])) {
                continue;
            }

            $teamId = $ridersBySlug[$slug]->team_id;
            if (!$teamId) {
                continue;
            }

            $features = $featuresBySlug[$slug] ?? [];
            $riderId = (int) ($slugToId[$slug] ?? 0);
            $startlistOrder = $riderId > 0 ? $startlistOrdersByRiderId->get($riderId) : null;
            $teamBuckets[$teamId][] = [
                'index' => $index,
                'slug' => $slug,
                'startlist_order' => is_numeric($startlistOrder) ? (int) $startlistOrder : null,
                'leader_score' => $this->intraTeamLeaderScore($features),
                'career_pct' => min(1.0, max(0.0, (float) ($features['field_pct_career_points'] ?? 0.5))),
                'pcs_pct' => min(1.0, max(0.0, (float) ($features['field_pct_pcs_ranking'] ?? 0.5))),
                'uci_pct' => min(1.0, max(0.0, (float) ($features['field_pct_uci_ranking'] ?? 0.5))),
                'recent_pct' => min(1.0, max(0.0, (float) ($features['field_pct_recent_form'] ?? 0.5))),
                'super_elite_score' => (
                    min(1.0, max(0.0, (float) ($features['field_pct_career_points'] ?? 0.5))) * 0.5
                    + min(1.0, max(0.0, (float) ($features['field_pct_pcs_ranking'] ?? 0.5))) * 0.3
                    + min(1.0, max(0.0, (float) ($features['field_pct_uci_ranking'] ?? 0.5))) * 0.2
                ),
            ];
        }

        foreach ($teamBuckets as $members) {
            if (count($members) < 2) {
                continue;
            }

            usort($members, function (array $a, array $b) use ($predictions) {
                $aOrder = $a['startlist_order'] ?? null;
                $bOrder = $b['startlist_order'] ?? null;
                $aHasOrder = is_int($aOrder) && $aOrder > 0;
                $bHasOrder = is_int($bOrder) && $bOrder > 0;

                if ($aHasOrder && $bHasOrder && $aOrder !== $bOrder) {
                    return $aOrder <=> $bOrder;
                }

                if ($aHasOrder !== $bHasOrder) {
                    return $aHasOrder ? -1 : 1;
                }

                $eliteDelta = ((float) ($b['super_elite_score'] ?? 0.0)) - ((float) ($a['super_elite_score'] ?? 0.0));
                if (abs($eliteDelta) >= 0.06) {
                    return $eliteDelta <=> 0.0;
                }

                $scoreCmp = $b['leader_score'] <=> $a['leader_score'];
                if ($scoreCmp !== 0) {
                    return $scoreCmp;
                }

                $aWin = (float) ($predictions[$a['index']]['win_probability'] ?? 0.0);
                $bWin = (float) ($predictions[$b['index']]['win_probability'] ?? 0.0);

                return $bWin <=> $aWin;
            });

            $leader = $members[0];
            $leaderIdx = $leader['index'];

            foreach (array_slice($members, 1) as $member) {
                $memberIdx = $member['index'];
                $gap = $leader['leader_score'] - $member['leader_score'];
                $leaderOrder = $leader['startlist_order'] ?? null;
                $memberOrder = $member['startlist_order'] ?? null;
                $orderGap = (is_int($leaderOrder) && is_int($memberOrder)) ? ($memberOrder - $leaderOrder) : 0;
                $hasOrderPriority = $orderGap >= 1;
                $isClassicOneDay = $race->isOneDay()
                    && in_array($race->parcours_type, ['cobbled', 'classic', 'hilly'], true);
                $orderGapTolerance = $isClassicOneDay ? -0.20 : -0.02;
                $orderEligible = $hasOrderPriority && $gap >= $orderGapTolerance;
                $leaderSuperElite = ((float) ($leader['career_pct'] ?? 0.5)) >= 0.985
                    && ((float) ($leader['pcs_pct'] ?? 0.5)) >= 0.975
                    && ((float) ($leader['uci_pct'] ?? 0.5)) >= 0.96;
                $eliteGap = ((float) ($leader['career_pct'] ?? 0.5)) - ((float) ($member['career_pct'] ?? 0.5));
                $leaderClearlyAboveTeammate = $eliteGap >= 0.04
                    || (((float) ($leader['leader_score'] ?? 0.0)) - ((float) ($member['leader_score'] ?? 0.0))) >= 0.08;

                if (!$orderEligible && $gap < 0.10) {
                    continue;
                }

                $leaderWin = (float) ($predictions[$leaderIdx]['win_probability'] ?? 0.0);
                $memberWin = (float) ($predictions[$memberIdx]['win_probability'] ?? 0.0);
                $leaderPos = (int) ($predictions[$leaderIdx]['predicted_position'] ?? 999);
                $memberPos = (int) ($predictions[$memberIdx]['predicted_position'] ?? 999);
                if ($orderEligible && $leaderWin < 0.02 && $memberWin > 0.08) {
                    continue;
                }

                $memberAhead = $memberPos < $leaderPos || $memberWin > ($leaderWin * 1.03);
                if (!$memberAhead) {
                    continue;
                }

                $orderBoost = $orderEligible
                    ? ($isClassicOneDay
                        ? min(0.12, max(0.03, $orderGap * 0.02))
                        : min(0.08, max(0.02, $orderGap * 0.015)))
                    : 0.0;
                $penaltyFactor = max(0.62, min(0.92, 1 - ($gap * 0.35) - $orderBoost));
                if ($leaderSuperElite && $leaderClearlyAboveTeammate && ($orderEligible || $hasOrderPriority || !$leaderOrder || !$memberOrder)) {
                    $penaltyFactor = min($penaltyFactor, $isClassicOneDay ? 0.58 : 0.64);
                }
                $newMemberWin = max(0.0, min(1.0, $memberWin * $penaltyFactor));
                $winTransfer = max(0.0, ($memberWin - $newMemberWin) * 0.8);
                $newLeaderWin = max(0.0, min(1.0, $leaderWin + $winTransfer));

                $memberTop10 = (float) ($predictions[$memberIdx]['top10_probability'] ?? 0.0);
                $leaderTop10 = (float) ($predictions[$leaderIdx]['top10_probability'] ?? 0.0);
                $newMemberTop10 = max(0.0, min(1.0, $memberTop10 * min(0.96, $penaltyFactor + 0.05)));
                $newLeaderTop10 = max(0.0, min(1.0, $leaderTop10 + max(0.0, ($memberTop10 - $newMemberTop10) * 0.5)));

                if ($isClassicOneDay && $orderEligible) {
                    $targetLeaderWin = $newMemberWin * 1.03;
                    if ($newLeaderWin < $targetLeaderWin) {
                        $winDelta = $targetLeaderWin - $newLeaderWin;
                        $newLeaderWin = min(1.0, $targetLeaderWin);
                        $newMemberWin = max(0.0, $newMemberWin - ($winDelta * 0.9));
                    }
                }
                if ($leaderSuperElite && $leaderClearlyAboveTeammate && ($orderEligible || $hasOrderPriority || !$leaderOrder || !$memberOrder)) {
                    $targetLeaderWin = $newMemberWin * ($isClassicOneDay ? 1.10 : 1.06);
                    if ($newLeaderWin < $targetLeaderWin) {
                        $winDelta = $targetLeaderWin - $newLeaderWin;
                        $newLeaderWin = min(1.0, $targetLeaderWin);
                        $newMemberWin = max(0.0, $newMemberWin - ($winDelta * 0.85));
                    }
                }

                $predictions[$memberIdx]['win_probability'] = $newMemberWin;
                $predictions[$memberIdx]['top10_probability'] = $newMemberTop10;
                $predictions[$leaderIdx]['win_probability'] = $newLeaderWin;
                $predictions[$leaderIdx]['top10_probability'] = $newLeaderTop10;

                $leader['leader_score'] = max($leader['leader_score'], $member['leader_score'] + 0.01);
            }

            // Geen harde ranking-lock meer op basis van startlijstvolgorde.
            // Dit gaf foutpositieven (bv. knecht boven duidelijke favorieten).
        }

        usort($predictions, function (array $a, array $b) {
            $aWin = (float) ($a['win_probability'] ?? 0.0);
            $bWin = (float) ($b['win_probability'] ?? 0.0);
            if ($aWin !== $bWin) {
                return $bWin <=> $aWin;
            }

            $aTop10 = (float) ($a['top10_probability'] ?? 0.0);
            $bTop10 = (float) ($b['top10_probability'] ?? 0.0);
            if ($aTop10 !== $bTop10) {
                return $bTop10 <=> $aTop10;
            }

            $aConfidence = (float) ($a['confidence_score'] ?? 0.0);
            $bConfidence = (float) ($b['confidence_score'] ?? 0.0);
            if ($aConfidence !== $bConfidence) {
                return $bConfidence <=> $aConfidence;
            }

            return ((int) ($a['predicted_position'] ?? 999)) <=> ((int) ($b['predicted_position'] ?? 999));
        });

        foreach ($predictions as $index => $prediction) {
            $predictions[$index]['predicted_position'] = $index + 1;
        }

        return $predictions;
    }

    private function intraTeamLeaderScore(array $features): float
    {
        $career = (float) ($features['field_pct_career_points'] ?? 0.5);
        $pcs = (float) ($features['field_pct_pcs_ranking'] ?? 0.5);
        $recent = (float) ($features['field_pct_recent_form'] ?? 0.5);
        $season = (float) ($features['field_pct_season_form'] ?? 0.5);
        $course = (float) ($features['field_pct_course_fit'] ?? 0.5);
        $top10 = (float) ($features['field_pct_top10_rate'] ?? 0.5);
        $dominance = min(1.0, max(0.0, (float) ($features['season_dominance_score'] ?? 50.0) / 100.0));
        $momentum = min(1.0, max(0.0, (float) ($features['current_year_attack_momentum_rate_parcours'] ?? $features['current_year_attack_momentum_rate'] ?? 0.0) / 100.0));
        $closeFinish = min(1.0, max(0.0, (float) ($features['current_year_close_finish_rate_parcours'] ?? $features['current_year_close_finish_rate'] ?? 0.0) / 100.0));

        return (
            $career * 0.19 +
            $pcs * 0.12 +
            $recent * 0.21 +
            $season * 0.20 +
            $course * 0.11 +
            $top10 * 0.05 +
            $dominance * 0.06 +
            $momentum * 0.04 +
            $closeFinish * 0.02
        );
    }

    private function applyClassicContenderMomentumAdjustments(
        array $predictions,
        Race $race,
        array $featuresBySlug,
        string $predictionType
    ): array {
        if (
            $predictionType !== 'result'
            || !$race->isOneDay()
            || count($predictions) < 2
        ) {
            return $predictions;
        }

        $isClassicLike = in_array($race->parcours_type, ['cobbled', 'classic', 'hilly'], true);
        $updated = false;

        foreach ($predictions as $index => $prediction) {
            $slug = (string) ($prediction['rider_slug'] ?? '');
            if ($slug === '' || !isset($featuresBySlug[$slug]) || !is_array($featuresBySlug[$slug])) {
                continue;
            }

            $features = $featuresBySlug[$slug];
            $momentum = min(1.0, max(0.0, (float) ($features['recent_one_day_momentum'] ?? 0.0)));
            $careerPct = min(1.0, max(0.0, (float) ($features['field_pct_career_points'] ?? 0.5)));
            $recentPct = min(1.0, max(0.0, (float) ($features['field_pct_recent_form'] ?? 0.5)));
            $seasonDom = min(1.0, max(0.0, (float) ($features['season_dominance_score'] ?? 50.0) / 100.0));
            $scenario = (
                min(1.0, max(0.0, (float) ($features['current_year_attack_momentum_rate_parcours'] ?? 0.0) / 100.0)) * 0.7
                + min(1.0, max(0.0, (float) ($features['current_year_close_finish_rate_parcours'] ?? 0.0) / 100.0)) * 0.3
            );
            $age = isset($features['age']) && is_numeric($features['age']) ? (float) $features['age'] : null;
            $currentYearAvg = isset($features['current_year_avg_position']) && is_numeric($features['current_year_avg_position'])
                ? (float) $features['current_year_avg_position']
                : null;
            $sprintSpeciality = min(1.0, max(0.0, (float) ($features['pcs_speciality_sprint'] ?? 0.0) / 10000.0));
            $hillsSpeciality = min(1.0, max(0.0, (float) ($features['pcs_speciality_hills'] ?? 0.0) / 10000.0));
            $climbSpeciality = min(1.0, max(0.0, (float) ($features['pcs_speciality_climber'] ?? 0.0) / 10000.0));
            $oneDaySpeciality = min(1.0, max(0.0, (float) ($features['pcs_speciality_one_day'] ?? 0.0) / 10000.0));
            $parcoursTop10Rate = max(0.0, min(100.0, (float) ($features['current_year_top10_rate_parcours'] ?? 0.0)));
            $avgParcoursPosition = isset($features['avg_position_parcours']) && is_numeric($features['avg_position_parcours'])
                ? (float) $features['avg_position_parcours']
                : 99.0;
            $parcoursResultsCount = max(0.0, (float) ($features['parcours_results_count'] ?? 0.0));
            $currentYearResultsCount = max(0.0, (float) ($features['current_year_results_count'] ?? 0.0));
            $classicFit = min(
                1.0,
                max(
                    0.0,
                    ($hillsSpeciality * 0.55) + ($climbSpeciality * 0.25) + ($oneDaySpeciality * 0.20)
                )
            );
            $pureSprinterClassicMismatch = $isClassicLike
                && $sprintSpeciality >= 0.48
                && $hillsSpeciality <= 0.34
                && $climbSpeciality <= 0.20
                && $scenario < 0.45
                && $avgParcoursPosition > 20.0
                && ($sprintSpeciality - (($hillsSpeciality * 0.85) + ($climbSpeciality * 0.15))) > 0.20;
            $lowClassicProfileMismatch = $isClassicLike
                && $classicFit < 0.18
                && $scenario < 0.32
                && $oneDaySpeciality < 0.36
                && $parcoursResultsCount <= 2.0
                && $currentYearResultsCount <= 3.0;
            $weakClassicProfile = $isClassicLike
                && $classicFit < 0.14
                && $oneDaySpeciality < 0.34
                && $scenario < 0.50;
            $recentOneDayPosition = isset($features['recent_one_day_position']) && is_numeric($features['recent_one_day_position'])
                ? (int) $features['recent_one_day_position']
                : null;
            $recentOneDayDaysAgo = isset($features['recent_one_day_days_ago']) && is_numeric($features['recent_one_day_days_ago'])
                ? (int) $features['recent_one_day_days_ago']
                : null;

            $freshPodiumSignal = $recentOneDayPosition !== null
                && $recentOneDayPosition <= 3
                && $recentOneDayDaysAgo !== null
                && $recentOneDayDaysAgo <= 14;
            $freshTop10Signal = $recentOneDayPosition !== null
                && $recentOneDayPosition <= 10
                && $recentOneDayDaysAgo !== null
                && $recentOneDayDaysAgo <= 10;
            if ($freshPodiumSignal) {
                $weakClassicProfile = false;
            }

            $youngTalentBreakthrough = $isClassicLike
                && $age !== null
                && $age <= 21.8
                && $currentYearAvg !== null
                && $currentYearAvg <= 12.0
                && $currentYearResultsCount >= 4.0;
            if ($youngTalentBreakthrough) {
                // U23's met duidelijke topvorm mogen niet door generieke caps
                // als "zwak klassiekerprofiel" worden platgeslagen.
                $weakClassicProfile = false;
                $lowClassicProfileMismatch = false;
            }

            $coreContender = $careerPct >= 0.90 && ($momentum >= 0.45 || $scenario >= 0.45 || $freshPodiumSignal);
            $eliteFallbackContender = $careerPct >= 0.97 && $seasonDom >= 0.62 && $recentPct >= 0.58;
            $eliteGeneralistContender = $careerPct >= 0.985 && $recentPct >= 0.60 && $seasonDom >= 0.60;

            if ($pureSprinterClassicMismatch) {
                $coreContender = false;
                $eliteFallbackContender = false;
                $eliteGeneralistContender = false;
            }
            if ($lowClassicProfileMismatch) {
                $eliteFallbackContender = false;
                $eliteGeneralistContender = false;
            }
            if ($weakClassicProfile) {
                $eliteGeneralistContender = false;
            }

            if ($coreContender || $eliteFallbackContender) {
                $boostFactor = 1.0
                    + ($momentum * 0.28)
                    + ($scenario * 0.12)
                    + ($seasonDom * 0.08)
                    + ($recentPct * 0.05);

                if ($freshPodiumSignal) {
                    $boostFactor += 0.55;
                } elseif ($freshTop10Signal) {
                    $boostFactor += 0.20;
                }

                if (
                    $recentOneDayPosition !== null
                    && $recentOneDayPosition <= 2
                    && $recentOneDayDaysAgo !== null
                    && $recentOneDayDaysAgo <= 10
                ) {
                    $boostFactor += 0.25;
                }

                if (
                    $recentOneDayPosition !== null
                    && $recentOneDayPosition <= 3
                    && $recentOneDayDaysAgo !== null
                    && $recentOneDayDaysAgo <= 10
                    && ($momentum >= 0.60 || $scenario >= 0.58)
                ) {
                    // Sterke recente klassiekerprestatie + zichtbaar koersverloop
                    // moet duidelijk doorwegen in volgende eendagskoers.
                    $boostFactor += 0.24;
                }

                if (
                    $recentOneDayPosition !== null
                    && $recentOneDayPosition <= 3
                    && $recentOneDayDaysAgo !== null
                    && $recentOneDayDaysAgo <= 7
                    && $momentum >= 0.65
                ) {
                    $boostFactor += 0.35;
                }

                if ($eliteFallbackContender && !$freshPodiumSignal) {
                    $boostFactor += 0.28;
                }

                $boostFactor = min(2.25, $boostFactor);
            } else {
                $boostFactor = 1.0 + ($momentum * 0.06) + ($scenario * 0.03);
                if ($pureSprinterClassicMismatch) {
                    $boostFactor *= 0.42;
                }
                if ($lowClassicProfileMismatch) {
                    $boostFactor *= 0.58;
                }
                if ($weakClassicProfile) {
                    $boostFactor *= 0.70;
                }
            }

            if ($eliteGeneralistContender) {
                // Elite all-rounders should remain explicit contenders across one-day races.
                $boostFactor += 0.22
                    + max(0.0, ($careerPct - 0.985)) * 2.0
                    + max(0.0, ($recentPct - 0.60)) * 0.45;

                if ($isClassicLike) {
                    $boostFactor += 0.10;
                }
            }
            if ($youngTalentBreakthrough) {
                $boostFactor += 0.16 + max(0.0, (12.0 - $currentYearAvg)) * 0.012;
                $boostFactor = min(2.35, $boostFactor);
            }

            $currentWin = (float) ($prediction['win_probability'] ?? 0.0);
            $currentTop10 = (float) ($prediction['top10_probability'] ?? 0.0);
            $newWin = max(0.0, min(1.0, $currentWin * $boostFactor));
            $newTop10 = max(0.0, min(0.98, $currentTop10 * (1.0 + (($boostFactor - 1.0) * 0.45))));

            if ($pureSprinterClassicMismatch) {
                // Pure sprinters zonder heuvel-/klassiekerfit mogen in
                // klassieke eendagskoersen niet als topfavoriet blijven staan.
                $newWin = min($newWin, 0.018);
                $newTop10 = min($newTop10, 0.22);
            }
            if ($lowClassicProfileMismatch) {
                // Voorkom dat mini-sample top-10 rates (bv. 1/1 = 100%)
                // klassieke ranking onrealistisch hoog trekken.
                $newWin = min($newWin, 0.028);
                $newTop10 = min($newTop10, 0.42);
            }
            if ($weakClassicProfile) {
                $newWin = min($newWin, 0.022);
                $newTop10 = min($newTop10, 0.36);
            }

            if ($eliteFallbackContender) {
                $eliteFloor = 0.055 + max(0.0, ($careerPct - 0.97)) * 0.22 + max(0.0, ($recentPct - 0.58)) * 0.08;
                $newWin = max($newWin, min(0.13, $eliteFloor));
            }
            if ($eliteGeneralistContender) {
                $generalistFloor = 0.09 + max(0.0, ($careerPct - 0.985)) * 0.40 + max(0.0, ($recentPct - 0.60)) * 0.12;
                $newWin = max($newWin, min(0.16, $generalistFloor));
                $newTop10 = max($newTop10, min(0.92, 0.52 + max(0.0, ($recentPct - 0.60)) * 0.5));
            }

            if (abs($newWin - $currentWin) > 0.0001 || abs($newTop10 - $currentTop10) > 0.0001) {
                $predictions[$index]['win_probability'] = $newWin;
                $predictions[$index]['top10_probability'] = $newTop10;
                $updated = true;
            }
        }

        if (!$updated) {
            return $predictions;
        }

        usort($predictions, function (array $a, array $b) {
            $aWin = (float) ($a['win_probability'] ?? 0.0);
            $bWin = (float) ($b['win_probability'] ?? 0.0);
            if ($aWin !== $bWin) {
                return $bWin <=> $aWin;
            }

            $aTop10 = (float) ($a['top10_probability'] ?? 0.0);
            $bTop10 = (float) ($b['top10_probability'] ?? 0.0);
            if ($aTop10 !== $bTop10) {
                return $bTop10 <=> $aTop10;
            }

            return ((int) ($a['predicted_position'] ?? 999)) <=> ((int) ($b['predicted_position'] ?? 999));
        });

        foreach ($predictions as $index => $prediction) {
            $predictions[$index]['predicted_position'] = $index + 1;
        }

        return $predictions;
    }

    private function raceTeamSignals(Race $race, Rider $rider): array
    {
        $default = [
            'team_startlist_size' => 1,
            'team_career_points_total' => (float) ($rider->career_points ?? 0.0),
            'team_career_points_share' => 0.0,
        ];

        $team = trim((string) ($rider->team ?? ''));
        if ($team === '') {
            return $default;
        }

        $cacheKey = "{$race->id}:{$race->year}";

        if (!array_key_exists($cacheKey, $this->raceTeamSignalsCache)) {
            $entries = $race->entries()
                ->with(['rider:id,team,career_points'])
                ->get();

            $teamBuckets = [];
            foreach ($entries as $entry) {
                if (!$entry->rider) {
                    continue;
                }

                $entryTeam = trim((string) ($entry->rider->team ?? ''));
                if ($entryTeam === '') {
                    continue;
                }

                if (!array_key_exists($entryTeam, $teamBuckets)) {
                    $teamBuckets[$entryTeam] = [
                        'team_startlist_size' => 0,
                        'team_career_points_total' => 0.0,
                    ];
                }

                $teamBuckets[$entryTeam]['team_startlist_size']++;
                $teamBuckets[$entryTeam]['team_career_points_total'] += max(0.0, (float) ($entry->rider->career_points ?? 0.0));
            }

            $maxTeamPoints = collect($teamBuckets)
                ->map(fn (array $signals) => (float) $signals['team_career_points_total'])
                ->max() ?? 0.0;

            foreach ($teamBuckets as $teamName => $signals) {
                $teamPoints = (float) $signals['team_career_points_total'];
                $teamBuckets[$teamName]['team_career_points_share'] = $maxTeamPoints > 0
                    ? round($teamPoints / $maxTeamPoints, 4)
                    : 0.0;
            }

            $this->raceTeamSignalsCache[$cacheKey] = $teamBuckets;
        }

        $signals = $this->raceTeamSignalsCache[$cacheKey][$team] ?? null;
        if (!is_array($signals)) {
            return $default;
        }

        return [
            'team_startlist_size' => max(1, (int) ($signals['team_startlist_size'] ?? 1)),
            'team_career_points_total' => round((float) ($signals['team_career_points_total'] ?? 0.0), 2),
            'team_career_points_share' => round((float) ($signals['team_career_points_share'] ?? 0.0), 4),
        ];
    }
}
