<?php

namespace App\Http\Controllers;

use App\Models\Prediction;
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
        ]);
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

        $this->predictionService->refreshRaceIfStale($race);
        $race->refresh();

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
            'entries' => $predictions->take(3)->map(function (Prediction $prediction) {
                $team = $prediction->rider->team?->name ?? 'Onbekend team';
                $winProbability = round($prediction->win_probability * 100, 1);
                $top10Probability = round($prediction->top10_probability * 100, 1);

                return [
                    'rider' => $prediction->rider->full_name,
                    'reason' => "{$team} • {$winProbability}% winkans • {$top10Probability}% top-10",
                    'label' => $this->entryLabel($prediction),
                    'signal' => $this->entrySignal($prediction),
                    'confidence' => round($prediction->confidence_score * 100) . '%',
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
