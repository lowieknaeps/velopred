<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\FormatsPredictionEvaluations;
use App\Models\Prediction;
use App\Models\Race;
use App\Services\PredictionService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PredictionController extends Controller
{
    use FormatsPredictionEvaluations;

    public function __construct(
        private PredictionService $predictionService,
    ) {}

    public function index(Request $request): Response
    {
        $today       = now()->toDateString();
        $currentYear = (int) date('Y');
        $selectedRaceSlug = trim((string) $request->query('race', ''));

        $availableRacesQuery = Race::relevant()
            ->where('year', $currentYear)
            ->whereHas('predictions');

        $availableRaces = (clone $availableRacesQuery)
            ->orderBy('start_date', 'asc')
            ->get();

        $selectedRace = null;

        if ($selectedRaceSlug !== '') {
            $selectedRace = (clone $availableRacesQuery)
                ->where('pcs_slug', $selectedRaceSlug)
                ->first();
        }

        // ── Zoek de meest relevante race met voorspellingen ──────────────────
        // Voorkeur: eerstvolgende komende race met voorspellingen
        $race = $selectedRace ?: (clone $availableRacesQuery)
            ->where('start_date', '>=', $today)
            ->orderBy('start_date', 'asc')
            ->first();

        // Fallback: meest recente afgelopen race met voorspellingen
        if (!$race) {
            $race = (clone $availableRacesQuery)
                ->orderBy('start_date', 'desc')
                ->first();
        }

        // ── Geen data beschikbaar ────────────────────────────────────────────
        if (!$race) {
            return Inertia::render('Predictions/Index', [
                'race'        => null,
                'predictions' => [],
                'scenarios'   => [],
                'availableRaces' => [],
                'otherRaces'  => [],
            ]);
        }

        $this->predictionService->refreshRaceIfStale($race);
        $race->refresh();

        $primaryContext = $this->primaryPredictionContext($race);

        // ── Top 10 voorspellingen — alleen renners die effectief starten ─────
        $startlistRiderIds = $race->entries()->pluck('rider_id');
        $hasStartlist      = $startlistRiderIds->isNotEmpty();

        $predictions = $race->predictions()
            ->when($hasStartlist, fn($q) => $q->whereIn('rider_id', $startlistRiderIds))
            ->orderBy('prediction_type')
            ->orderBy('stage_number')
            ->orderBy('predicted_position')
            ->with('rider.team')
            ->get();

        $primaryPredictions = $predictions
            ->filter(fn($prediction) => $prediction->prediction_type === $primaryContext['prediction_type']
                && (int) $prediction->stage_number === (int) $primaryContext['stage_number'])
            ->sortBy('predicted_position')
            ->take(10)
            ->values();
        $latestPrediction = $predictions
            ->sortByDesc(fn ($prediction) => optional($prediction->updated_at)->timestamp ?? 0)
            ->first();

        // ── Actuele uitslag (als de race al gereden is) ──────────────────────
        $actualResults = $race->results()
            ->where('result_type', $primaryContext['prediction_type'])
            ->when($primaryContext['prediction_type'] === 'stage', fn($query) => $query->where('stage_number', $primaryContext['stage_number']))
            ->whereNotNull('position')
            ->where('status', 'finished')
            ->orderBy('position')
            ->limit(10)
            ->with('rider')
            ->get();

        // ── Bouw predictions payload ─────────────────────────────────────────
        // Map actuele uitslag op rider_id voor vergelijking
        $actualByRider = $actualResults->keyBy('rider_id');

        $predictionList = $primaryPredictions->map(function ($p) use ($actualByRider) {
            $actual = $actualByRider->get($p->rider_id);
            return [
                'position'          => $p->predicted_position,
                'rider_slug'        => $p->rider->pcs_slug,
                'rider'             => $p->rider->full_name,
                'team'              => $p->rider->team?->name ?? '–',
                'win_probability'   => round($p->win_probability * 100, 1),
                'top10_probability' => round($p->top10_probability * 100, 1),
                'confidence'        => round($p->confidence_score * 100, 0),
                'actual_position'   => $actual?->position,
                'features'          => $p->features,
            ];
        })->values()->toArray();

        $predictionGroups = $this->formatPredictionGroups($predictions, $primaryContext);

        // ── Scenarios op basis van race en voorspellingen ────────────────────
        $scenarios = $this->buildScenarios($race, $primaryPredictions);

        // ── Andere races met voorspellingen (sidebar) ─────────────────────────
        $otherRaces = Race::relevant()
            ->where('year', $currentYear)
            ->where('id', '!=', $race->id)
            ->whereHas('predictions')
            ->orderBy('start_date', 'asc')
            ->limit(6)
            ->get()
            ->map(fn(Race $r) => [
                'slug'     => $r->pcs_slug,
                'name'     => $r->name,
                'date'     => $r->start_date->locale('nl_BE')->translatedFormat('d M'),
                'terrain'  => ucfirst($r->parcours_type),
                'upcoming' => $r->start_date->gte(now()),
            ]);

        return Inertia::render('Predictions/Index', [
            'race' => [
                'slug'        => $race->pcs_slug,
                'name'        => $race->name,
                'date'        => $race->start_date->locale('nl_BE')->translatedFormat('d M Y'),
                'terrain'     => ucfirst($race->parcours_type),
                'category'    => $race->category,
                'is_finished' => $race->hasFinished(),
                'is_live'     => $race->isLive(),
                'has_results' => $actualResults->isNotEmpty(),
                'startlist_count' => $hasStartlist ? $startlistRiderIds->count() : null,
                'startlist_synced_at' => $this->formatTimestamp($race->startlist_synced_at),
                'prediction_model_version' => $latestPrediction?->model_version,
                'prediction_updated_at' => $this->formatTimestamp($latestPrediction?->updated_at),
                'primary_prediction_title' => $this->predictionContextLabel($primaryContext['prediction_type'], $primaryContext['stage_number']),
            ],
            'predictions' => $predictionList,
            'predictionGroups' => $predictionGroups,
            'scenarios'   => $scenarios,
            'evaluation'  => $this->formatEvaluationPayload($race, $primaryContext),
            'availableRaces' => $availableRaces
                ->map(fn(Race $r) => [
                    'slug' => $r->pcs_slug,
                    'name' => $r->name,
                    'date' => $r->start_date->locale('nl_BE')->translatedFormat('d M'),
                    'terrain' => ucfirst($r->parcours_type),
                    'is_selected' => $r->id === $race->id,
                    'upcoming' => $r->start_date->gte(now()),
                ])
                ->values(),
            'otherRaces'  => $otherRaces,
        ]);
    }

    // ── Scenarios ─────────────────────────────────────────────────────────────

    private function buildScenarios(Race $race, $predictions): array
    {
        if ($predictions->isEmpty()) {
            return [];
        }

        $top3         = $predictions->take(3)->values();
        $winner       = $top3->first();
        $parcours     = $race->parcours_type;
        $usedRiderIds = [(int) $winner->rider_id];

        $scenarios = [];

        $specialists = $predictions
            ->filter(fn($p) => ($p->features['race_specificity_ratio'] ?? 1) > 2.5)
            ->values();

        $spec = $this->pickScenarioCandidate($specialists, $top3, $usedRiderIds);
        if ($spec) {
            $historySnippet = $this->scenarioHistorySnippet($spec);
            $challengers    = $this->scenarioChallengersText($top3, $spec->rider_id);

            $scenarios[] = [
                'title' => match($parcours) {
                    'cobbled'  => '🪨 Kasseienscenario',
                    'mountain' => '⛰️ Klimscenario',
                    'hilly'    => '🏔️ Puncheurscenario',
                    'classic'  => '🏆 Klassiekerscenario',
                    default    => '🎯 Specialistscenario',
                },
                'text'   => "{$spec->rider->full_name} scoort historisch uitzonderlijk sterk op dit parcours {$historySnippet}. "
                    . ($challengers
                        ? "{$challengers} blijven wel de belangrijkste uitdagers als de finale selectief wordt."
                        : 'Als het profiel zijn sterktes uitspeelt, is hij moeilijk te kloppen.'),
                'effect' => "Vergroot de kansen van specialisten t.o.v. all-rounders.",
            ];
        }

        $inForm = $predictions
            ->filter(fn($p) => ($p->features['form_trend'] ?? 0) < -3)
            ->values();

        $hot = $this->pickScenarioCandidate($inForm, $top3, $usedRiderIds);
        if ($hot) {
            $challengers = $this->scenarioChallengersText($top3, $hot->rider_id);

            $scenarios[] = [
                'title'  => '🔥 Vormscenario',
                'text'   => "{$hot->rider->full_name} rijdt momenteel beter dan zijn historisch gemiddelde. "
                    . ($challengers
                        ? "Samen met {$challengers} hoort hij bij de renners die koers hard kunnen maken."
                        : 'Recente resultaten zijn aanzienlijk sterker dan verwacht.'),
                'effect' => "Renners in vorm zijn gevaarlijker dan hun historische ranking doet vermoeden.",
            ];
        }

        $top5Win     = $predictions->take(5)->sum(fn($p) => $p->win_probability);
        $winnerShare = $winner->win_probability / max($top5Win, 0.01);
        $top3Names   = $this->formatScenarioNameList($top3->pluck('rider.full_name')->all());
        $challengers = $this->scenarioChallengersText($top3, $winner->rider_id);

        if ($winnerShare < 0.35) {
            $scenarios[] = [
                'title'  => '🎲 Open koers scenario',
                'text'   => "De winkansen liggen dicht bij elkaar tussen {$top3Names}. "
                    . 'Tactiek, positionering en het koersverloop worden daardoor doorslaggevend.',
                'effect' => "Hogere onzekerheid: outsiders hebben een reële kans.",
            ];
        } else {
            $scenarios[] = [
                'title'  => '👑 Duidelijke favoriet scenario',
                'text'   => "{$winner->rider->full_name} heeft een significant voordeel op basis van historische prestaties "
                    . "(" . round($winner->win_probability * 100, 1) . "% winkans). "
                    . ($challengers
                        ? "{$challengers} blijven de dichtste uitdagers voor winst en podium."
                        : 'Andere ploegen zullen zich moeten organiseren om hem te counteren.'),
                'effect' => "De grootste podiumkansen concentreren zich bij de topfavorieten.",
            ];
        }

        return $scenarios;
    }

    private function pickScenarioCandidate($candidates, $top3, array &$usedRiderIds)
    {
        $candidate = $candidates->first(function ($prediction) use ($top3, $usedRiderIds) {
            return $top3->contains('rider_id', $prediction->rider_id)
                && !in_array((int) $prediction->rider_id, $usedRiderIds, true);
        });

        if (!$candidate) {
            $candidate = $candidates->first(
                fn($prediction) => !in_array((int) $prediction->rider_id, $usedRiderIds, true)
            );
        }

        if (!$candidate) {
            return null;
        }

        $usedRiderIds[] = (int) $candidate->rider_id;

        return $candidate;
    }

    private function scenarioHistorySnippet($prediction): string
    {
        $avgPosition = $prediction->features['avg_position_this_race'] ?? null;

        if ($avgPosition === null) {
            return 'en past aantoonbaar goed bij dit koersprofiel';
        }

        return '(gemiddelde positie ' . round((float) $avgPosition, 1) . ' op deze koers)';
    }

    private function scenarioChallengersText($predictions, int $excludeRiderId): ?string
    {
        $names = $predictions
            ->filter(fn($prediction) => (int) $prediction->rider_id !== (int) $excludeRiderId)
            ->take(2)
            ->pluck('rider.full_name')
            ->all();

        if (empty($names)) {
            return null;
        }

        return $this->formatScenarioNameList($names);
    }

    private function formatScenarioNameList(array $names): string
    {
        $names = array_values(array_filter($names));
        $count = count($names);

        if ($count === 0) {
            return '';
        }

        if ($count === 1) {
            return $names[0];
        }

        if ($count === 2) {
            return "{$names[0]} en {$names[1]}";
        }

        $last = array_pop($names);

        return implode(', ', $names) . " en {$last}";
    }

    private function primaryPredictionContext(Race $race): array
    {
        return $race->isOneDay()
            ? ['prediction_type' => 'result', 'stage_number' => 0]
            : ['prediction_type' => 'gc', 'stage_number' => 0];
    }

    private function predictionContextLabel(string $predictionType, int $stageNumber = 0): string
    {
        return match($predictionType) {
            'stage'  => "Etappe {$stageNumber}",
            'gc'     => 'Eindklassement',
            'points' => 'Puntenklassement',
            'kom'    => 'Bergklassement',
            'youth'  => 'Jongerenklassement',
            default  => 'Uitslag',
        };
    }

    private function predictionContextSort(string $predictionType, int $stageNumber = 0): int
    {
        return match($predictionType) {
            'gc'     => 0,
            'result' => 50,
            'stage'  => 100 + $stageNumber,
            'points' => 300,
            'kom'    => 400,
            'youth'  => 500,
            default  => 600,
        };
    }

    private function formatPredictionGroups($predictions, array $primaryContext): array
    {
        return $predictions
            ->groupBy(fn($prediction) => $prediction->prediction_type . ':' . (int) $prediction->stage_number)
            ->sortBy(fn($group) => $this->predictionContextSort(
                $group->first()->prediction_type,
                (int) $group->first()->stage_number
            ))
            ->map(function ($group) use ($primaryContext) {
                $first = $group->first();

                return [
                    'key'        => $first->prediction_type . ':' . (int) $first->stage_number,
                    'title'      => $this->predictionContextLabel($first->prediction_type, (int) $first->stage_number),
                    'is_primary' => $first->prediction_type === $primaryContext['prediction_type']
                        && (int) $first->stage_number === (int) $primaryContext['stage_number'],
                    'predictions' => $group
                        ->sortBy('predicted_position')
                        ->take(10)
                        ->map(function ($prediction) {
                            return [
                                'position'          => $prediction->predicted_position,
                                'rider_slug'        => $prediction->rider->pcs_slug,
                                'rider'             => $prediction->rider->full_name,
                                'team'              => $prediction->rider->team?->name ?? '–',
                                'win_probability'   => round($prediction->win_probability * 100, 1),
                                'top10_probability' => round($prediction->top10_probability * 100, 1),
                                'confidence'        => round($prediction->confidence_score * 100, 0),
                            ];
                        })
                        ->values()
                        ->toArray(),
                ];
            })
            ->values()
            ->all();
    }

    private function formatTimestamp($value): ?string
    {
        return $value?->copy()->locale('nl_BE')->translatedFormat('d M HH:mm');
    }
}
