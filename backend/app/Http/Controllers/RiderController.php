<?php

namespace App\Http\Controllers;

use App\Models\Prediction;
use App\Models\RaceResult;
use App\Models\Rider;
use App\Services\ExternalCyclingApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class RiderController extends Controller
{
    public function index(): Response
    {
        $today = now()->toDateString();
        $currentYear = (int) date('Y');

        $predictionSummary = Prediction::query()
            ->join('races', 'races.id', '=', 'predictions.race_id')
            ->join('race_entries', function ($join) {
                $join->on('race_entries.race_id', '=', 'predictions.race_id')
                    ->on('race_entries.rider_id', '=', 'predictions.rider_id');
            })
            ->where('races.year', $currentYear)
            ->where('races.start_date', '>=', $today)
            ->groupBy('predictions.rider_id')
            ->selectRaw('predictions.rider_id')
            ->selectRaw('MIN(predictions.predicted_position) as best_predicted_position')
            ->selectRaw('MAX(predictions.win_probability) as best_win_probability');

        $seasonSummary = RaceResult::query()
            ->join('races', 'races.id', '=', 'race_results.race_id')
            ->where('races.year', $currentYear)
            ->whereIn('race_results.result_type', ['result', 'gc'])
            ->whereNotNull('race_results.position')
            ->where('race_results.status', 'finished')
            ->groupBy('race_results.rider_id')
            ->selectRaw('race_results.rider_id')
            ->selectRaw('COUNT(*) as season_result_count')
            ->selectRaw('AVG(race_results.position) as season_avg_position')
            ->selectRaw('SUM(CASE WHEN race_results.position <= 10 THEN 1 ELSE 0 END) as season_top10_count')
            ->selectRaw('SUM(CASE WHEN race_results.position = 1 THEN 1 ELSE 0 END) as season_win_count');

        $riders = Rider::query()
            ->leftJoinSub($predictionSummary, 'prediction_summary', function ($join) {
                $join->on('prediction_summary.rider_id', '=', 'riders.id');
            })
            ->leftJoinSub($seasonSummary, 'season_summary', function ($join) {
                $join->on('season_summary.rider_id', '=', 'riders.id');
            })
            ->with([
                'team',
                'predictions' => function ($query) use ($today, $currentYear) {
                    $query->join('races', 'races.id', '=', 'predictions.race_id')
                        ->join('race_entries', function ($join) {
                            $join->on('race_entries.race_id', '=', 'predictions.race_id')
                                ->on('race_entries.rider_id', '=', 'predictions.rider_id');
                        })
                        ->where('races.year', $currentYear)
                        ->where('races.start_date', '>=', $today)
                        ->orderByDesc('predictions.win_probability')
                        ->orderBy('predictions.predicted_position')
                        ->select('predictions.*')
                        ->with(['race:id,name,pcs_slug,start_date']);
                },
            ])
            ->where(function ($query) use ($currentYear) {
                $query->whereNotNull('prediction_summary.best_predicted_position')
                    ->orWhereHas('raceResults.race', fn($raceQuery) => $raceQuery->where('year', $currentYear));
            })
            ->orderByRaw('CASE WHEN prediction_summary.best_predicted_position IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('prediction_summary.best_win_probability')
            ->orderBy('prediction_summary.best_predicted_position')
            ->orderByRaw('COALESCE(season_summary.season_top10_count, 0) DESC')
            ->orderByRaw('COALESCE(riders.career_points, 0) DESC')
            ->select([
                'riders.*',
                DB::raw('prediction_summary.best_predicted_position as best_predicted_position'),
                DB::raw('prediction_summary.best_win_probability as best_win_probability'),
                DB::raw('season_summary.season_result_count as season_result_count'),
                DB::raw('season_summary.season_avg_position as season_avg_position'),
                DB::raw('season_summary.season_top10_count as season_top10_count'),
                DB::raw('season_summary.season_win_count as season_win_count'),
            ])
            ->get();

        return Inertia::render('Riders/Index', [
            'riders' => $riders->map(fn(Rider $rider) => $this->formatRiderCard($rider)),
        ]);
    }

    public function show(Request $request, string $slug): Response
    {
        $rider = Rider::where('pcs_slug', $slug)
            ->with('team')
            ->firstOrFail();
        $today = now()->toDateString();
        $currentYear = (int) date('Y');

        // Recente resultaten (laatste 10), gesorteerd op racedag
        $recentResults = $rider->raceResults()
            ->whereIn('result_type', ['result', 'gc'])
            ->whereNotNull('position')
            ->where('status', 'finished')
            ->with('race')
            ->join('races', 'race_results.race_id', '=', 'races.id')
            ->orderByDesc('races.start_date')
            ->select('race_results.*')
            ->limit(10)
            ->get();

        $avgPosition  = $recentResults->count() > 0
            ? round($recentResults->avg('position'), 1)
            : null;

        $top10Count = $recentResults->filter(fn($r) => $r->position <= 10)->count();
        $winsCount  = $recentResults->filter(fn($r) => $r->position === 1)->count();

        $upcomingPredictions = $rider->predictions()
            ->join('races', 'races.id', '=', 'predictions.race_id')
            ->join('race_entries', function ($join) {
                $join->on('race_entries.race_id', '=', 'predictions.race_id')
                    ->on('race_entries.rider_id', '=', 'predictions.rider_id');
            })
            ->where('races.year', $currentYear)
            ->where('races.start_date', '>=', $today)
            ->orderByDesc('predictions.win_probability')
            ->orderBy('predictions.predicted_position')
            ->select('predictions.*')
            ->with(['race:id,name,pcs_slug,start_date'])
            ->limit(6)
            ->get();

        $bestUpcomingPrediction = $upcomingPredictions->first();

        $indicators = [
            [
                'label' => 'Gemiddelde positie',
                'value' => $avgPosition ? "#$avgPosition" : '–',
                'text'  => 'Gemiddelde eindpositie over de gesynchroniseerde koersen.',
            ],
            [
                'label' => 'Top-10 plaatsen',
                'value' => $top10Count > 0 ? "{$top10Count}x" : '–',
                'text'  => "Aantal top-10 plaatsen in de laatste {$recentResults->count()} resultaten.",
            ],
            [
                'label' => 'Overwinningen',
                'value' => $winsCount > 0 ? "{$winsCount}x" : '–',
                'text'  => 'Aantal overwinningen in de gesynchroniseerde koersen.',
            ],
        ];

        // Recente resultaten voor de tabel
        $resultsTable = $recentResults->map(fn($r) => [
            'race'     => $r->race->name,
            'slug'     => $r->race->pcs_slug,
            'type'     => match($r->result_type) {
                'gc'     => 'Eindklassement',
                'result' => 'Uitslag',
                'stage'  => 'Etappe',
                default  => ucfirst($r->result_type),
            },
            'position' => $r->position,
            'status'   => $r->status,
            'date'     => $r->race->start_date->locale('nl_BE')->translatedFormat('d M Y'),
            'parcours' => ucfirst($r->race->parcours_type ?? '–'),
        ])->values()->toArray();

        $upcomingTable = $upcomingPredictions->map(fn($prediction) => [
            'race'            => $prediction->race->name,
            'slug'            => $prediction->race->pcs_slug,
            'date'            => $prediction->race->start_date->locale('nl_BE')->translatedFormat('d M Y'),
            'context'         => $this->predictionContextLabel($prediction->prediction_type, (int) $prediction->stage_number),
            'position'        => $prediction->predicted_position,
            'win_probability' => round($prediction->win_probability * 100, 1),
            'top10_probability' => round($prediction->top10_probability * 100, 1),
        ])->values()->toArray();

        // ── Explainability (optioneel, vanuit race/context) ───────────────────
        $explain = null;
        $raceSlug = trim((string) $request->query('race', ''));
        $type = trim((string) $request->query('type', ''));
        $stage = (int) $request->query('stage', 0);
        if ($raceSlug !== '' && $type !== '') {
            $prediction = Prediction::query()
                ->join('races', 'races.id', '=', 'predictions.race_id')
                ->where('races.pcs_slug', $raceSlug)
                ->where('predictions.rider_id', $rider->id)
                ->where('predictions.prediction_type', $type)
                ->when($type === 'stage', fn ($q) => $q->where('predictions.stage_number', $stage))
                ->orderByDesc('predictions.updated_at')
                ->select('predictions.*')
                ->with('race:id,name,pcs_slug,start_date,parcours_type')
                ->first();

            if ($prediction) {
                $explain = [
                    'race' => [
                        'slug' => $prediction->race->pcs_slug,
                        'name' => $prediction->race->name,
                        'date' => $prediction->race->start_date->locale('nl_BE')->translatedFormat('d M Y'),
                        'context' => $this->predictionContextLabel($prediction->prediction_type, (int) $prediction->stage_number),
                    ],
                    'model_version' => $prediction->model_version,
                    'signals' => $this->explainSignals($prediction->features ?? [], $prediction->prediction_type),
                ];
            }
        }

        return Inertia::render('Riders/Show', [
            'rider' => [
                ...$this->formatRiderCard($rider),
                'photo_url'      => $this->fetchPcsPhotoUrl($rider->pcs_slug),
                'nationality'   => $rider->nationality,
                'date_of_birth' => $rider->date_of_birth?->locale('nl_BE')->translatedFormat('d M Y'),
                'age'           => $rider->age,
                'outlook'       => $bestUpcomingPrediction
                    ? "{$rider->full_name} staat momenteel als #{$bestUpcomingPrediction->predicted_position} geprojecteerd voor {$bestUpcomingPrediction->race->name} ({$this->predictionContextLabel($bestUpcomingPrediction->prediction_type, (int) $bestUpcomingPrediction->stage_number)}) met " . round($bestUpcomingPrediction->win_probability * 100, 1) . "% winkans."
                    : 'Nog geen komende startlijstgebonden voorspellingen voor deze renner.',
            ],
            'indicators'    => $indicators,
            'recentResults' => $resultsTable,
            'upcomingPredictions' => $upcomingTable,
            'explainability' => $explain,
        ]);
    }

    private function explainSignals(array $features, string $predictionType): array
    {
        $signals = [];

        $cyAvg = is_numeric($features['current_year_avg_position'] ?? null) ? (float) $features['current_year_avg_position'] : null;
        $recentAvg = is_numeric($features['recent_avg_position'] ?? null) ? (float) $features['recent_avg_position'] : null;
        $cyTop10 = is_numeric($features['current_year_top10_rate'] ?? null) ? (float) $features['current_year_top10_rate'] : null;
        $courseFit = is_numeric($features['field_pct_course_fit'] ?? null) ? (float) $features['field_pct_course_fit'] : null;
        $gcSpec = is_numeric($features['pcs_speciality_gc'] ?? null) ? ((float) $features['pcs_speciality_gc'] / 10000.0) : null;
        $climbSpec = is_numeric($features['pcs_speciality_climber'] ?? null) ? ((float) $features['pcs_speciality_climber'] / 10000.0) : null;
        $sprintSpec = is_numeric($features['pcs_speciality_sprint'] ?? null) ? ((float) $features['pcs_speciality_sprint'] / 10000.0) : null;
        $incidentPenalty = is_numeric($features['manual_incident_penalty'] ?? null) ? (float) $features['manual_incident_penalty'] : 0.0;
        $lastIncidentDays = is_numeric($features['pcs_last_incident_days_ago'] ?? null) ? (float) $features['pcs_last_incident_days_ago'] : null;

        if ($cyAvg !== null || $cyTop10 !== null || $recentAvg !== null) {
            $desc = [];
            if ($cyAvg !== null) $desc[] = "Gem. positie 2026: #".round($cyAvg, 1);
            if ($cyTop10 !== null) $desc[] = "Top-10 rate 2026: ".round($cyTop10, 0)."%";
            if ($recentAvg !== null) $desc[] = "Recente gem.: #".round($recentAvg, 1);
            $signals[] = [
                'label' => 'Vorm',
                'value' => $cyAvg !== null ? "#".round($cyAvg, 1) : ($recentAvg !== null ? "#".round($recentAvg, 1) : '–'),
                'tone'  => ($cyAvg !== null && $cyAvg <= 12) || ($cyTop10 !== null && $cyTop10 >= 50) ? 'good' : (($cyAvg !== null && $cyAvg >= 22) ? 'bad' : 'neutral'),
                'detail'=> implode(' · ', $desc),
            ];
        }

        if ($courseFit !== null) {
            $signals[] = [
                'label' => 'Parcoursfit',
                'value' => round($courseFit * 100, 0).'%',
                'tone'  => $courseFit >= 0.75 ? 'good' : ($courseFit <= 0.40 ? 'bad' : 'neutral'),
                'detail'=> 'Relatieve fit binnen dit startveld (parcours + context).',
            ];
        }

        if (in_array($predictionType, ['gc', 'youth'], true) && ($gcSpec !== null || $climbSpec !== null)) {
            $signals[] = [
                'label' => 'GC profiel',
                'value' => $gcSpec !== null ? round($gcSpec * 100, 0).'%' : '–',
                'tone'  => ($gcSpec !== null && $gcSpec >= 0.70) || ($climbSpec !== null && $climbSpec >= 0.75) ? 'good' : 'neutral',
                'detail'=> 'PCS-specialities (GC/klimmen/TT) als profiel-indicator.',
            ];
        }

        if ($predictionType === 'stage' && $sprintSpec !== null) {
            $signals[] = [
                'label' => 'Sprint/Punch',
                'value' => round($sprintSpec * 100, 0).'%',
                'tone'  => $sprintSpec >= 0.65 ? 'good' : ($sprintSpec <= 0.30 ? 'bad' : 'neutral'),
                'detail'=> 'PCS sprint-speciality (context-afhankelijk).',
            ];
        }

        if ($incidentPenalty > 0.0 || ($lastIncidentDays !== null && $lastIncidentDays <= 45.0)) {
            $detail = $incidentPenalty > 0 ? "Incident-penalty: ".round($incidentPenalty * 100, 0)."%" : null;
            if ($lastIncidentDays !== null) {
                $detail = trim(($detail ? $detail.' · ' : '')."Laatste incident: ".round($lastIncidentDays, 0)."d geleden");
            }
            $signals[] = [
                'label' => 'Incident',
                'value' => $incidentPenalty > 0 ? '-'.round($incidentPenalty * 100, 0).'%' : '–',
                'tone'  => 'bad',
                'detail'=> $detail ?: 'Recente incidenten / comeback-signaal.',
            ];
        }

        return array_slice($signals, 0, 5);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function formatRiderCard(Rider $rider): array
    {
        $bestPrediction = $rider->predictions->first();

        $seasonResultCount = (int) ($rider->season_result_count ?? 0);
        $seasonTop10Count = (int) ($rider->season_top10_count ?? 0);
        $seasonWinCount = (int) ($rider->season_win_count ?? 0);
        $seasonAvgPosition = $rider->season_avg_position !== null
            ? round((float) $rider->season_avg_position, 1)
            : null;

        $trend = $seasonResultCount > 0
            ? "{$seasonTop10Count} top-10 plaatsen in {$seasonResultCount} uitslagen van 2026"
            : 'Nog geen uitslagen in 2026';

        if ($seasonWinCount > 0) {
            $trend = "{$seasonWinCount} overwinning(en) en {$seasonTop10Count} top-10 plaatsen in 2026";
        }

        return [
            'slug'            => $rider->pcs_slug,
            'name'            => $rider->full_name,
            'team'            => $rider->team?->name ?? '–',
            'profile'         => implode(' · ', array_filter([
                $rider->nationality,
                $rider->age ? "{$rider->age} jaar" : null,
                $rider->team?->name,
            ])),
            'rating'          => $bestPrediction ? "#{$bestPrediction->predicted_position}" : ($rider->pcs_ranking ? "#{$rider->pcs_ranking}" : '–'),
            'ratingLabel'     => $bestPrediction ? 'Beste voorspelling' : 'PCS-ranking',
            'strength'        => $seasonAvgPosition ? "Gem. positie #{$seasonAvgPosition}" : ($rider->career_points ? number_format((int) $rider->career_points, 0, ',', '.') . ' punten' : '–'),
            'strengthLabel'   => $seasonAvgPosition ? 'Seizoensvorm' : 'Carrièrepunten',
            'modelFit'        => $bestPrediction
                ? "{$bestPrediction->race->name} · {$this->predictionContextLabel($bestPrediction->prediction_type, (int) $bestPrediction->stage_number)} · " . round($bestPrediction->win_probability * 100, 1) . "% winkans"
                : 'Nog geen komende voorspelling',
            'modelFitLabel'   => 'Beste koers',
            'trend'           => $trend,
            'trendLabel'      => 'Recente output',
            'predictionRace'  => $bestPrediction?->race?->name,
            'predictionDate'  => $bestPrediction?->race?->start_date?->locale('nl_BE')->translatedFormat('d M Y'),
        ];
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

    private function fetchPcsPhotoUrl(string $pcsSlug): ?string
    {
        try {
            $api = new ExternalCyclingApiService();
            $profile = $api->getRider($pcsSlug);
            $photoUrl = $profile['photo_url'] ?? null;

            if (!is_string($photoUrl) || trim($photoUrl) === '') {
                return $this->localPhotoUrl($pcsSlug);
            }

            $photoUrl = trim($photoUrl);
            if (str_starts_with($photoUrl, 'http://') || str_starts_with($photoUrl, 'https://')) {
                return $photoUrl;
            }

            $normalizedPath = ltrim($photoUrl, '/');
            return "https://www.procyclingstats.com/{$normalizedPath}";
        } catch (\Throwable) {
            return $this->localPhotoUrl($pcsSlug);
        }
    }

    private function localPhotoUrl(string $pcsSlug): ?string
    {
        $extensions = ['webp', 'jpg', 'jpeg', 'png'];

        foreach ($extensions as $extension) {
            $relativePath = "images/riders/{$pcsSlug}.{$extension}";
            if (is_file(public_path($relativePath))) {
                return asset($relativePath);
            }
        }

        return null;
    }
}
