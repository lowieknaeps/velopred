<?php

namespace App\Http\Controllers;

use App\Models\Prediction;
use App\Models\PredictionEvaluation;
use App\Models\Race;
use App\Services\PredictionService;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __construct(
        private PredictionService $predictionService,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Dashboard', [
            'liveBoard' => $this->buildLiveBoard(),
            'evaluationSummary' => $this->buildEvaluationSummary(),
            'featuredRaces' => $this->buildFeaturedRaces(),
            'featuredRiders' => $this->buildFeaturedRiders(),
        ]);
    }

    private function buildFeaturedRaces(): array
    {
        $today = now()->toDateString();
        $year = (int) date('Y');

        $baseQuery = Race::relevant()
            ->where('year', $year)
            ->whereHas('entries')
            ->whereHas('predictions');

        $upcoming = (clone $baseQuery)
            ->where('start_date', '>=', $today)
            ->orderBy('start_date')
            ->limit(3)
            ->get();

        $finished = (clone $baseQuery)
            ->where('end_date', '<', $today)
            ->orderBy('end_date', 'desc')
            ->limit(max(0, 3 - $upcoming->count()))
            ->get();

        $races = $upcoming->concat($finished)->values();

        return $races->map(function (Race $race) {
            $startlistRiderIds = $race->entries()->pluck('rider_id');

            $predictions = $race->predictions()
                ->where('prediction_type', $race->isOneDay() ? 'result' : 'gc')
                ->where('stage_number', 0)
                ->whereIn('rider_id', $startlistRiderIds)
                ->orderBy('predicted_position')
                ->limit(1)
                ->with('rider.team')
                ->get();

            $topPrediction = $predictions->first();
            $topPick = $topPrediction?->rider?->full_name;

            $winner = null;
            if ($race->isOneDay()) {
                $winner = $race->results()
                    ->where('result_type', 'result')
                    ->whereNull('stage_number')
                    ->where('status', 'finished')
                    ->where('position', 1)
                    ->with('rider')
                    ->first();
            }

            $raceTypeLabel = match (true) {
                $race->category === 'Monument' => 'Monument',
                $race->isOneDay() => 'Eendagskoers',
                default => 'Etappekoers',
            };

            $terrainKey = $race->parcours_type;
            $terrainLabel = match ($terrainKey) {
                'cobbled' => 'Kasseien',
                'hilly' => 'Heuvels',
                'mountain' => 'Bergen',
                'flat' => 'Vlak',
                default => ucfirst((string) $terrainKey),
            };

            $isFinished = $race->end_date?->lt(now()->startOfDay()) ?? false;
            $summary = $isFinished
                ? 'Uitslag is binnen: check hoe de top-10 voorspelling stand hield.'
                : 'Startlijst en modelprojectie staan klaar voor deze wedstrijd.';

            return [
                'slug' => $race->pcs_slug,
                'name' => $race->name,
                'category' => $race->category,
                'date' => $race->start_date->locale('nl_BE')->translatedFormat('d M Y'),
                'race_type' => $raceTypeLabel,
                'terrain' => $terrainLabel,
                'terrain_key' => $terrainKey,
                'summary' => $summary,
                'hasPredictions' => $topPick !== null,
                'topPickLabel' => $isFinished && $winner ? 'Winnaar' : 'Topfavoriet',
                'topPick' => $isFinished && $winner ? $winner->rider->full_name : ($topPick ?? 'Nog geen voorspelling'),
                'rider_count' => $race->entries()->count(),
                'win_probability' => $topPrediction ? round((float) $topPrediction->win_probability * 100, 1) : null,
            ];
        })
            ->filter(fn (array $race) => (bool) ($race['hasPredictions'] ?? false))
            ->values()
            ->all();
    }

    private function buildFeaturedRiders(): array
    {
        $board = $this->buildLiveBoard();
        if (!$board || empty($board['entries'])) {
            return [];
        }

        return collect($board['entries'])
            ->take(3)
            ->map(function (array $entry) {
                return [
                    'slug' => $entry['rider_slug'] ?? null,
                    'name' => $entry['rider'],
                    'team' => explode('•', $entry['reason'])[0] ?? 'Onbekend team',
                    'profile' => 'Live geselecteerd op basis van startlijst-gekoppelde voorspellingen.',
                    'rating' => $entry['confidence'] ?? '–',
                    'ratingLabel' => 'Betrouwbaarheid',
                    'strengthLabel' => 'Signaal',
                    'strength' => $entry['signal'] ?? '–',
                    'modelFitLabel' => 'Waarom',
                    'modelFit' => $entry['label'] ?? 'Model signaal',
                    'trendLabel' => 'Context',
                    'trend' => $entry['reason'] ?? '',
                ];
            })
            ->filter(fn (array $rider) => !empty($rider['slug']))
            ->values()
            ->all();
    }

    private function buildEvaluationSummary(): ?array
    {
        $evaluations = PredictionEvaluation::query()
            ->where('prediction_type', 'result')
            ->where('stage_number', 0)
            ->with('race:id,pcs_slug,name,year,start_date')
            ->orderByDesc('evaluated_at')
            ->limit(5)
            ->get();

        if ($evaluations->isEmpty()) {
            return null;
        }

        $latest = $evaluations->first();
        $recent = $evaluations->values();

        $avgTop10 = round((float) $recent->avg('top10_hits'), 1);
        $avgExact = round((float) $recent->avg('exact_position_hits'), 1);
        $winnerHitRate = round(((float) $recent->avg(fn (PredictionEvaluation $e) => $e->winner_hit ? 1 : 0)) * 100, 0);

        $latestRace = $latest->race;
        $raceDate = $latestRace?->start_date?->locale('nl_BE')->translatedFormat('d M Y');

        return [
            'latest' => [
                'race' => [
                    'slug' => $latestRace?->pcs_slug,
                    'name' => $latestRace?->name,
                    'year' => $latestRace?->year,
                    'date' => $raceDate,
                ],
                'top10_hits' => (int) $latest->top10_hits,
                'exact_hits' => (int) $latest->exact_position_hits,
                'winner_hit' => (bool) $latest->winner_hit,
                'mae' => $latest->mean_absolute_position_error !== null ? round((float) $latest->mean_absolute_position_error, 1) : null,
                'evaluated_at' => $latest->evaluated_at?->timezone('Europe/Brussels')->format('d-m-Y H:i'),
            ],
            'recent' => [
                'count' => $recent->count(),
                'avg_top10_hits' => $avgTop10,
                'avg_exact_hits' => $avgExact,
                'winner_hit_rate_pct' => (int) $winnerHitRate,
            ],
        ];
    }

    private function buildLiveBoard(): ?array
    {
        $today = now()->toDateString();
        $year = (int) date('Y');

        $baseQuery = Race::relevant()
            ->where('year', $year)
            ->whereHas('entries')
            ->whereHas('predictions');

        $race = (clone $baseQuery)
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->orderBy('start_date')
            ->first();

        if (!$race) {
            $race = (clone $baseQuery)
                ->where('start_date', '>=', $today)
                ->orderBy('start_date')
                ->first();
        }

        if (!$race) {
            $race = (clone $baseQuery)
                ->orderBy('start_date', 'desc')
                ->first();
        }

        if (!$race) {
            return null;
        }

        $startlistRiderIds = $race->entries()->pluck('rider_id');

        $predictions = $race->predictions()
            ->where('prediction_type', $race->isOneDay() ? 'result' : 'gc')
            ->where('stage_number', 0)
            ->whereIn('rider_id', $startlistRiderIds)
            ->orderBy('predicted_position')
            ->limit(5)
            ->with('rider.team')
            ->get();

        if ($predictions->isEmpty()) {
            return null;
        }

        $topPrediction = $predictions->first();

        return [
            'name' => $race->name,
            'slug' => $race->pcs_slug,
            'date' => $race->start_date->locale('nl_BE')->translatedFormat('d M Y'),
            'terrain' => ucfirst($race->parcours_type),
            'category' => $race->category,
            'confidence' => round($predictions->avg('confidence_score') * 100),
            'leadScenarioTitle' => $this->leadScenarioTitle($race->parcours_type),
            'leadScenarioText' => $this->leadScenarioText($race, $topPrediction),
            'breakPointTitle' => $this->breakPointTitle($race->parcours_type),
            'breakPointText' => $this->breakPointText($race->parcours_type),
            'aiNote' => $this->aiNote($topPrediction),
            'entries' => $predictions->take(3)->values()->map(function (Prediction $prediction, int $index) {
                $team = $prediction->rider->team?->name ?? 'Onbekend team';
                $winProbability = round($prediction->win_probability * 100, 1);
                $top10Probability = round($prediction->top10_probability * 100, 1);

                return [
                    'position' => $index + 1,
                    'rider_slug' => $prediction->rider->pcs_slug,
                    'rider' => $prediction->rider->full_name,
                    'team' => $team,
                    'win_probability' => $winProbability,
                    'top10_probability' => $top10Probability,
                    'confidence' => (int) round($prediction->confidence_score * 100),
                    'reason' => "{$team} • {$winProbability}% winkans • {$top10Probability}% top-10",
                    'label' => $this->entryLabel($prediction),
                    'signal' => $this->entrySignal($prediction),
                    'confidenceLabel' => round($prediction->confidence_score * 100) . '%',
                ];
            })->values()->toArray(),
        ];
    }

    private function leadScenarioTitle(string $terrain): string
    {
        return match ($terrain) {
            'cobbled' => 'Selectieve klassiekersfinale',
            'mountain' => 'Klimselectie',
            'hilly', 'classic' => 'Explosieve finaleaanval',
            'flat' => 'Gecontroleerd sprintscenario',
            default => 'Koersbeslissende selectie',
        };
    }

    private function leadScenarioText(Race $race, Prediction $prediction): string
    {
        $rider = $prediction->rider->full_name;
        $winProbability = round($prediction->win_probability * 100, 1);

        return match ($race->parcours_type) {
            'cobbled' => "{$rider} leidt het bord voor {$race->name} met {$winProbability}% winkans. Positionering en herhaalde inspanningen op de kasseistroken zijn hier doorslaggevend.",
            'mountain' => "{$rider} start als favoriet voor {$race->name}. Als de zwaarste klimsecties het verschil maken, verschuift de koers snel naar pure klimcapaciteit.",
            'flat' => "{$rider} staat bovenaan voor {$race->name}, maar op een vlak profiel blijven teamcontrole en sprinttiming bepalend voor de finale.",
            default => "{$rider} opent als topfavoriet voor {$race->name} met {$winProbability}% winkans. De finale zal waarschijnlijk beslist worden door een selecte groep met de beste vorm en koersspecifieke fit.",
        };
    }

    private function breakPointTitle(string $terrain): string
    {
        return match ($terrain) {
            'cobbled' => 'Sleutelsector',
            'mountain' => 'Sleutelklim',
            'flat' => 'Finale-opbouw',
            default => 'Beslissende fase',
        };
    }

    private function breakPointText(string $terrain): string
    {
        return match ($terrain) {
            'cobbled' => 'De beslissing valt meestal wanneer positionering op de kasseien ploegsterkte begint te overstijgen.',
            'mountain' => 'Zodra het tempo op de zwaarste hellingen omhoog gaat, wordt het verschil snel permanent.',
            'flat' => 'Lead-outs, waaiers en ploegcontrole bepalen of de favorieten beschermd aan de sprint beginnen.',
            default => 'De koers kantelt wanneer het tempo hoog genoeg wordt om pure inhoud belangrijker te maken dan alleen tactisch wachten.',
        };
    }

    private function aiNote(Prediction $prediction): string
    {
        $winsThisRace = (int) ($prediction->features['wins_this_race'] ?? 0);
        $podiumsThisRace = (int) ($prediction->features['podiums_this_race'] ?? 0);
        $currentYearAverage = $prediction->features['current_year_avg_position'] ?? null;

        if ($winsThisRace > 0) {
            return "{$prediction->rider->full_name} krijgt extra gewicht door {$winsThisRace} eerdere zege(s) in deze koers.";
        }

        if ($podiumsThisRace > 0) {
            return "{$prediction->rider->full_name} scoort historisch sterk op deze wedstrijd met {$podiumsThisRace} podiumplaats(en).";
        }

        if ($currentYearAverage !== null) {
            return "{$prediction->rider->full_name} komt binnen met een gemiddeld resultaat van " . round((float) $currentYearAverage, 1) . " dit seizoen.";
        }

        return 'De ranking combineert startlijst, parcours-fit, recente vorm en koershistoriek in één live bord.';
    }

    private function entryLabel(Prediction $prediction): string
    {
        if (($prediction->features['wins_this_race'] ?? 0) > 0) {
            return 'Koershistoriek';
        }

        if (($prediction->features['wins_current_year'] ?? 0) > 0) {
            return 'Seizoensvorm';
        }

        return 'Model signaal';
    }

    private function entrySignal(Prediction $prediction): string
    {
        $winsThisRace = (int) ($prediction->features['wins_this_race'] ?? 0);
        if ($winsThisRace > 0) {
            return $winsThisRace . 'x winst in deze koers';
        }

        $winsCurrentYear = (int) ($prediction->features['wins_current_year'] ?? 0);
        if ($winsCurrentYear > 0) {
            return $winsCurrentYear . 'x winst dit seizoen';
        }

        $avgCurrentYear = $prediction->features['current_year_avg_position'] ?? null;
        if ($avgCurrentYear !== null) {
            return 'Gem. positie ' . round((float) $avgCurrentYear, 1) . ' in 2026';
        }

        return round($prediction->top10_probability * 100, 1) . '% top-10 kans';
    }
}
