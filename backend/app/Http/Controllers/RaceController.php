<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\FormatsPredictionEvaluations;
use App\Models\Race;
use App\Models\Rider;
use App\Models\Team;
use App\Services\PredictionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class RaceController extends Controller
{
    use FormatsPredictionEvaluations;
    private const MONUMENT_SLUGS = [
        'milano-sanremo',
        'ronde-van-vlaanderen',
        'paris-roubaix',
        'liege-bastogne-liege',
        'il-lombardia',
    ];
    private const RERUN_STATUS_TTL_HOURS = 2;
    private const RERUN_MAX_RUNNING_MINUTES = 12;
    private const RERUN_LEGACY_MAX_RUNNING_MINUTES = 8;
    private const RERUN_EXPECTED_SECONDS = 180;

    public function __construct(
        private PredictionService $predictionService,
    ) {}

    public function index(): Response
    {
        $currentYear = (int) date('Y');

        $seasonRaces = Race::relevant()
            ->where('year', $currentYear)
            ->orderBy('start_date', 'asc')
            ->get();

        $ongoing = $seasonRaces
            ->filter(fn(Race $race) => $race->isLive())
            ->values();

        $upcoming = $seasonRaces
            ->filter(fn(Race $race) => !$race->hasStarted())
            ->values();

        $recentPast = $seasonRaces
            ->filter(fn(Race $race) => $race->hasFinished())
            ->sortByDesc(fn(Race $race) => $race->start_date)
            ->values();

        // Vorig jaar — voor context/vergelijking
        $lastYear = Race::relevant()
            ->where('year', $currentYear - 1)
            ->orderBy('start_date', 'desc')
            ->get();

        // Highlights op basis van huidig seizoen
        $highlights = [
            [
                'label' => 'Dit seizoen',
                'value' => $seasonRaces->count() . ' races',
                'text'  => 'Mannenelite koersen in ' . $currentYear . ', inclusief extra klassiekers buiten WorldTour.',
            ],
            [
                'label' => 'Binnenkort',
                'value' => $upcoming->count() . ' races',
                'text'  => 'Wedstrijden die nog op de agenda staan dit seizoen.',
            ],
            [
                'label' => 'Afgelopen',
                'value' => $recentPast->count() . ' races',
                'text'  => 'Gereden wedstrijden waarvan resultaten beschikbaar zijn.',
            ],
        ];

        return Inertia::render('Races/Index', [
            'highlights' => $highlights,
            'ongoing'    => $ongoing->map(fn(Race $r) => $this->formatRaceCard($r)),
            'upcoming'   => $upcoming->map(fn(Race $r) => $this->formatRaceCard($r)),
            'recentPast' => $recentPast->map(fn(Race $r) => $this->formatRaceCard($r)),
            'lastYear'   => $lastYear->map(fn(Race $r) => $this->formatRaceCard($r)),
            // Legacy: races = alles samen voor componenten die dat verwachten
            'races'      => $ongoing
                ->concat($upcoming)
                ->concat($recentPast)
                ->map(fn(Race $r) => $this->formatRaceCard($r)),
        ]);
    }

    public function show(string $slug): Response
    {
        $race = Race::relevant()->where('pcs_slug', $slug)->orderBy('year', 'desc')->firstOrFail();
        $primaryContext = $this->primaryPredictionContext($race);

        // ── Actuele resultaten (als de race al gereden is) ──────────────────
        $actualResults = $race->results()
            ->where('result_type', $primaryContext['prediction_type'])
            ->when($primaryContext['prediction_type'] === 'stage', fn($query) => $query->where('stage_number', $primaryContext['stage_number']))
            ->whereNotNull('position')->where('status', 'finished')
            ->orderBy('position')->limit(10)
            ->with('rider.team')->get();

        // ── Predictions (top 10, gefilterd op startlijst als beschikbaar) ──
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

        $predictionGroups = $this->formatPredictionGroups($race, $predictions, $primaryContext);

        // ── Contenders (voor het Show component) ───────────────────────────
        $contenders = [];
        if ($actualResults->isNotEmpty()) {
            $contenders = $actualResults->take(3)->map(fn($r, $i) => [
                'name'       => $r->rider->full_name,
                'role'       => match($i) { 0 => 'Winnaar', 1 => '2e', default => '3e' },
                'note'       => $r->rider->team?->name ?? '–',
                'confidence' => '–',
            ])->values()->toArray();
        } elseif ($primaryPredictions->isNotEmpty()) {
            $contenders = $primaryPredictions->take(3)->map(fn($p, $i) => [
                'name'       => $p->rider->full_name,
                'role'       => match($i) { 0 => 'Topfavoriet', 1 => 'Kanshebber', default => 'Donkere outsider' },
                'note'       => round($p->win_probability * 100, 1) . '% winkans',
                'confidence' => round($p->top10_probability * 100, 0) . '% top-10',
            ])->values()->toArray();
        }

        // ── Race scenarios ──────────────────────────────────────────────────
        $scenarios = $this->buildScenarios($race, $primaryPredictions);

        // ── Signalen ────────────────────────────────────────────────────────
        $participantCount = $actualResults->count() ?: $race->entries()->count() ?: '–';
        $signals = [
            [
                'label' => 'Parcours',
                'value' => ucfirst($race->parcours_type),
                'text'  => $this->parcoursDescription($race->parcours_type),
            ],
            [
                'label' => 'Koerstype',
                'value' => $race->isOneDay() ? 'Eendagskoers' : 'Etappekoers',
                'text'  => $race->isOneDay()
                    ? 'Alles op één dag — tactiek en timing zijn cruciaal.'
                    : 'Meerdere etappes — vermoeidheid en klassement tellen zwaar.',
            ],
            [
                'label' => 'Deelnemers',
                'value' => $participantCount . ($participantCount !== '–' ? ' renners' : ''),
                'text'  => $actualResults->isNotEmpty()
                    ? 'Gefinishte renners in de uitslag.'
                    : 'Renners in de startlijst.',
            ],
        ];

        $isFinished = $race->hasFinished();
        $isLive = $race->isLive();

        return Inertia::render('Races/Show', [
            'race'        => [
                ...$this->formatRaceCard($race),
                'year'      => $race->year,
                'is_finished' => $isFinished,
                'is_live'   => $isLive,
                'startlist_count' => $hasStartlist ? $startlistRiderIds->count() : null,
                'startlist_synced_at' => $this->formatTimestamp($race->startlist_synced_at),
                'prediction_model_version' => $latestPrediction?->model_version,
                'prediction_updated_at' => $this->formatTimestamp($latestPrediction?->updated_at),
                'outlook'   => $scenarios['outlook'] ?? '',
                'primaryPredictionTitle' => $this->predictionContextLabel($primaryContext['prediction_type'], $primaryContext['stage_number']),
            ],
            'signals'     => $signals,
            'contenders'  => $contenders,
            'predictions' => $predictionList,
            'predictionGroups' => $predictionGroups,
            'scenarios'   => $scenarios['list'] ?? [],
            'has_results' => $actualResults->isNotEmpty(),
            'evaluation'  => $this->formatEvaluationPayload($race, $primaryContext),
        ]);
    }

    public function rerunModel(string $slug): RedirectResponse
    {
        $race = Race::relevant()
            ->where('pcs_slug', $slug)
            ->orderBy('year', 'desc')
            ->firstOrFail();
        $latestPredictionAt = $this->asCarbon($race->predictions()->max('updated_at'));
        $primaryContext = $this->primaryPredictionContext($race);
        $baselineSnapshot = $this->buildTopPredictionSnapshot($race, $primaryContext);
        $startedAt = now();
        $token = (string) Str::uuid();
        $lockFile = "/tmp/velopred-rerun-{$race->id}-{$token}.lock";
        $doneFile = "/tmp/velopred-rerun-{$race->id}-{$token}.done";
        $logFile = "/tmp/velopred-rerun-{$race->id}-{$token}.log";

        Cache::put($this->rerunStatusCacheKey($race), [
            'status' => 'running',
            'started_at' => $startedAt->toIso8601String(),
            'baseline_prediction_updated_at' => $latestPredictionAt?->toIso8601String(),
            'baseline_context' => $primaryContext,
            'baseline_snapshot' => $baselineSnapshot,
            'run_token' => $token,
            'lock_file' => $lockFile,
            'done_file' => $doneFile,
            'log_file' => $logFile,
        ], now()->addHours(self::RERUN_STATUS_TTL_HOURS));

        // Draai de predict-command echt detached met lock/done marker.
        // Zo kunnen we status betrouwbaar volgen, ook na pagina-refresh.
        $cmd = sprintf(
            "/bin/sh -lc %s",
            escapeshellarg(sprintf(
                'touch %s; cd %s && %s artisan predict:race %s %s > %s 2>&1; rc=$?; echo $rc > %s; rm -f %s',
                escapeshellarg($lockFile),
                escapeshellarg(base_path()),
                escapeshellarg(PHP_BINARY),
                escapeshellarg($race->pcs_slug),
                escapeshellarg((string) $race->year),
                escapeshellarg($logFile),
                escapeshellarg($doneFile),
                escapeshellarg($lockFile),
            ))
        );
        exec($cmd . ' > /dev/null 2>&1 &');

        return back(303);
    }

    public function rerunModelStatus(string $slug): JsonResponse
    {
        $race = Race::relevant()
            ->where('pcs_slug', $slug)
            ->orderBy('year', 'desc')
            ->firstOrFail();

        $state = Cache::get($this->rerunStatusCacheKey($race));
        $latestPredictionAt = $this->asCarbon($race->predictions()->max('updated_at'));

        if (!$state) {
            return response()->json([
                'status' => 'idle',
                'progress_percent' => 0,
                'latest_prediction_updated_at' => $this->formatTimestamp($latestPredictionAt),
            ]);
        }

        $startedAt = isset($state['started_at']) ? Carbon::parse($state['started_at']) : null;
        $baselineUpdatedAt = isset($state['baseline_prediction_updated_at']) && $state['baseline_prediction_updated_at']
            ? Carbon::parse($state['baseline_prediction_updated_at'])
            : null;

        if (($state['status'] ?? 'running') === 'running') {
            $doneFile = $state['done_file'] ?? null;
            $lockFile = $state['lock_file'] ?? null;
            $hasLockOrDoneMarkers = is_string($doneFile) || is_string($lockFile);
            $isProcessDone = is_string($doneFile) && is_file($doneFile);

            if ($isProcessDone) {
                $exitCode = (int) trim((string) @file_get_contents($doneFile));
                $isSuccess = $exitCode === 0;

                $state = [
                    ...$state,
                    'status' => $isSuccess ? 'completed' : 'failed',
                    'completed_at' => now()->toIso8601String(),
                    'exit_code' => $exitCode,
                ];

                Cache::put($this->rerunStatusCacheKey($race), $state, now()->addMinutes(30));
            }

            $isCompleted = false;

            if ($latestPredictionAt) {
                $isCompleted = $baselineUpdatedAt
                    ? $latestPredictionAt->gt($baselineUpdatedAt)
                    : (!$startedAt || $latestPredictionAt->gte($startedAt->copy()->subSeconds(10)));
            }

            if (($state['status'] ?? 'running') === 'running' && $isCompleted) {
                $state = [
                    ...$state,
                    'status' => 'completed',
                    'completed_at' => now()->toIso8601String(),
                ];

                Cache::put($this->rerunStatusCacheKey($race), $state, now()->addMinutes(30));
            } elseif (
                ($state['status'] ?? 'running') === 'running'
                && !$hasLockOrDoneMarkers
                && $startedAt
                && $startedAt->diffInMinutes(now(), true) >= self::RERUN_LEGACY_MAX_RUNNING_MINUTES
            ) {
                // Oudere runs (zonder lock/done metadata) mogen niet eindeloos op 95% blijven hangen.
                $state = [
                    ...$state,
                    'status' => 'failed',
                    'failed_at' => now()->toIso8601String(),
                ];

                Cache::put($this->rerunStatusCacheKey($race), $state, now()->addMinutes(30));
            } elseif (
                ($state['status'] ?? 'running') === 'running'
                && $startedAt
                && $startedAt->diffInMinutes(now(), true) >= self::RERUN_MAX_RUNNING_MINUTES
            ) {
                // Nieuwe runs met lock/done mogen ook niet oneindig blijven hangen.
                // Als een lock-file achterblijft door crash/kill, markeer toch als failed na timeout.
                $state = [
                    ...$state,
                    'status' => 'failed',
                    'failed_at' => now()->toIso8601String(),
                ];

                Cache::put($this->rerunStatusCacheKey($race), $state, now()->addMinutes(30));
            }
        }

        $progressPercent = match ($state['status'] ?? 'idle') {
            'completed' => 100,
            'failed' => 100,
            'running' => $this->estimateRunningProgressPercent($startedAt),
            default => 0,
        };
        $changeSummary = $this->buildRerunChangeSummary($race, $state);

        return response()->json([
            'status' => $state['status'] ?? 'idle',
            'progress_percent' => $progressPercent,
            'latest_prediction_updated_at' => $this->formatTimestamp($latestPredictionAt),
            'started_at' => isset($state['started_at']) ? $this->formatTimestamp(Carbon::parse($state['started_at'])) : null,
            'completed_at' => isset($state['completed_at']) ? $this->formatTimestamp(Carbon::parse($state['completed_at'])) : null,
            'change_summary' => $changeSummary,
        ]);
    }

    private function rerunStatusCacheKey(Race $race): string
    {
        return "race:{$race->id}:rerun-model-status";
    }

    private function asCarbon(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if (empty($value)) {
            return null;
        }

        return Carbon::parse($value);
    }

    private function estimateRunningProgressPercent(?Carbon $startedAt): int
    {
        if (!$startedAt) {
            return 5;
        }

        $elapsedSeconds = max(0, $startedAt->diffInSeconds(now(), true));
        $ratio = min(1, $elapsedSeconds / self::RERUN_EXPECTED_SECONDS);

        return (int) max(5, min(95, round($ratio * 95)));
    }

    private function buildTopPredictionSnapshot(Race $race, array $context): array
    {
        return $race->predictions()
            ->where('prediction_type', $context['prediction_type'])
            ->where('stage_number', (int) $context['stage_number'])
            ->orderBy('predicted_position')
            ->with('rider:id,pcs_slug,first_name,last_name')
            ->limit(10)
            ->get()
            ->map(function ($prediction) {
                return [
                    'rider_slug' => $prediction->rider->pcs_slug,
                    'rider' => $prediction->rider->full_name,
                    'position' => (int) $prediction->predicted_position,
                    'win_probability' => round((float) $prediction->win_probability * 100, 1),
                ];
            })
            ->all();
    }

    private function buildRerunChangeSummary(Race $race, array $state): ?array
    {
        $baselineSnapshot = collect($state['baseline_snapshot'] ?? []);
        $context = $state['baseline_context'] ?? $this->primaryPredictionContext($race);

        if ($baselineSnapshot->isEmpty()) {
            return null;
        }

        $currentSnapshot = collect($this->buildTopPredictionSnapshot($race, $context));
        if ($currentSnapshot->isEmpty()) {
            return null;
        }

        $baselineBySlug = $baselineSnapshot->keyBy('rider_slug');
        $currentBySlug = $currentSnapshot->keyBy('rider_slug');

        $overlapSlugs = $currentBySlug->keys()->intersect($baselineBySlug->keys())->values();
        $top10Overlap = $overlapSlugs->count();
        $exactPositions = $overlapSlugs
            ->filter(fn ($slug) => (int) ($currentBySlug[$slug]['position'] ?? 0) === (int) ($baselineBySlug[$slug]['position'] ?? -1))
            ->count();
        $newEntries = $currentBySlug->keys()->diff($baselineBySlug->keys())->count();
        $droppedEntries = $baselineBySlug->keys()->diff($currentBySlug->keys())->count();

        $movers = $overlapSlugs
            ->map(function ($slug) use ($baselineBySlug, $currentBySlug) {
                $oldPosition = (int) ($baselineBySlug[$slug]['position'] ?? 0);
                $newPosition = (int) ($currentBySlug[$slug]['position'] ?? 0);
                $delta = $oldPosition - $newPosition;

                return [
                    'rider' => $currentBySlug[$slug]['rider'] ?? $baselineBySlug[$slug]['rider'] ?? $slug,
                    'delta' => $delta,
                    'old_position' => $oldPosition,
                    'new_position' => $newPosition,
                ];
            })
            ->filter(fn (array $entry) => $entry['delta'] !== 0)
            ->sortByDesc(fn (array $entry) => abs($entry['delta']))
            ->values();

        $winProbabilityShifts = $overlapSlugs
            ->filter(function ($slug) use ($baselineBySlug, $currentBySlug) {
                $oldWin = (float) ($baselineBySlug[$slug]['win_probability'] ?? 0.0);
                $newWin = (float) ($currentBySlug[$slug]['win_probability'] ?? 0.0);

                return abs($newWin - $oldWin) >= 0.2;
            })
            ->count();

        return [
            'top10_overlap' => $top10Overlap,
            'exact_positions' => $exactPositions,
            'new_entries' => $newEntries,
            'dropped_entries' => $droppedEntries,
            'win_probability_shifts' => $winProbabilityShifts,
            'movers' => $movers->take(3)->all(),
            'has_changes' => $exactPositions < 10 || $newEntries > 0 || $droppedEntries > 0 || $winProbabilityShifts > 0,
            'current_snapshot' => $currentSnapshot->all(),
        ];
    }

    private function buildScenarios(Race $race, $predictions): array
    {
        if ($predictions->isEmpty()) {
            return ['outlook' => 'Geen voorspellingsdata beschikbaar.', 'list' => []];
        }

        $top3         = $predictions->take(3)->values();
        $winner       = $top3->first();
        $parcours     = $race->parcours_type;
        $usedRiderIds = [(int) $winner->rider_id];

        $specialists = $predictions
            ->filter(fn($p) => ($p->features['race_specificity_ratio'] ?? 1) > 2.5)
            ->values();

        $inForm = $predictions
            ->filter(fn($p) => ($p->features['form_trend'] ?? 0) < -3)
            ->values();
        $teamControl = $top3
            ->filter(fn ($p) => ($p->features['team_career_points_share'] ?? 0) >= 0.82)
            ->sortByDesc(fn ($p) => $p->features['team_career_points_share'] ?? 0)
            ->values();
        $attackers = $predictions
            ->filter(fn ($p) => ($p->features['current_year_attack_momentum_rate'] ?? 0) >= 35)
            ->values();

        $winPct       = round($winner->win_probability * 100, 1);
        $challengers  = $this->scenarioChallengersText($top3, $winner->rider_id);
        $outlook = "{$winner->rider->full_name} gaat als topfavoriet van start met {$winPct}% winkans"
            . ($challengers ? ", met {$challengers} als dichtste uitdagers." : '.');

        $list = [];

        $spec = $this->pickScenarioCandidate($specialists, $top3, $usedRiderIds);
        if ($spec) {
            $historySnippet   = $this->scenarioHistorySnippet($spec);
            $specChallengers  = $this->scenarioChallengersText($top3, $spec->rider_id);

            $list[] = [
                'title' => match($parcours) {
                    'cobbled'  => '🪨 Kasseienscenario',
                    'mountain' => '⛰️ Klimscenario',
                    'hilly'    => '🏔️ Puncheurscenario',
                    default    => '🎯 Specialistscenario',
                },
                'text' => "{$spec->rider->full_name} is historisch de specialist op dit parcours {$historySnippet}. "
                    . ($specChallengers
                        ? "{$specChallengers} blijven wel in de buurt als de finale niet volledig ontploft."
                        : 'Als het profiel zijn sterktes uitspeelt, is hij moeilijk te kloppen.'),
            ];
        }

        $hot = $this->pickScenarioCandidate($inForm, $top3, $usedRiderIds);
        if ($hot) {
            $formChallengers = $this->scenarioChallengersText($top3, $hot->rider_id);

            $list[] = [
                'title' => '🔥 Vormscenario',
                'text'  => "{$hot->rider->full_name} rijdt momenteel uitstekend en komt met een sterkere recente vormcurve aan de start. "
                    . ($formChallengers
                        ? "{$formChallengers} blijven de meest logische tegenkandidaten in een snelle finale."
                        : 'Een verrassing is daardoor zeker mogelijk.'),
            ];
        }

        $teamLeader = $teamControl->first();
        if ($teamLeader) {
            $teamShare = round((float) ($teamLeader->features['team_career_points_share'] ?? 0) * 100, 0);
            $teamChallengers = $this->scenarioChallengersText($top3, $teamLeader->rider_id);
            $list[] = [
                'title' => '🧩 Ploegscenario',
                'text'  => "{$teamLeader->rider->full_name} start met een sterk ploegblok ({$teamShare}% relatieve teamsterkte in deze startlijst). "
                    . ($teamChallengers
                        ? "{$teamChallengers} moeten die ploegcontrole breken voor een open finale."
                        : 'Als het peloton gesloten blijft, speelt dit voordeel nog zwaarder door.'),
            ];
        }

        $attacker = $this->pickScenarioCandidate($attackers, $top3, $usedRiderIds);
        if ($attacker) {
            $attackRate = round((float) ($attacker->features['current_year_attack_momentum_rate'] ?? 0), 0);
            $list[] = [
                'title' => '⚡ Aanvalsscenario',
                'text'  => "{$attacker->rider->full_name} toont dit seizoen vaak aanvalsmomentum ({$attackRate}% signaalscore). "
                    . 'Bij een vroege selectieve move stijgt zijn winstkans relatief het meest.',
            ];
        }

        $top5winChances = $predictions->take(5)->sum(fn($p) => $p->win_probability);
        $winnerShare    = $winner->win_probability / max($top5winChances, 0.01);

        if ($winnerShare < 0.35) {
            $list[] = [
                'title' => '🎲 Open koers scenario',
                'text'  => 'De winkansen liggen dicht bij elkaar tussen '
                    . $this->formatScenarioNameList($top3->pluck('rider.full_name')->all())
                    . '. Tactiek, timing en ploegenspel worden daardoor doorslaggevend.',
            ];
        } else {
            $list[] = [
                'title' => '👑 Duidelijke favoriet scenario',
                'text'  => "{$winner->rider->full_name} heeft een significant voordeel op basis van historische prestaties. "
                    . ($challengers
                        ? "{$challengers} blijven wel de dichtste uitdagers voor winst en podium."
                        : 'Andere ploegen zullen zich moeten organiseren om hem te counteren.'),
            ];
        }

        return ['outlook' => $outlook, 'list' => $list];
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

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function formatRaceCard(Race $race): array
    {
        $primaryContext = $this->primaryPredictionContext($race);
        $isFinished = $race->hasFinished();
        $isLive = $race->isLive();

        // Echte winnaar (als race al gereden is)
        $winner = $isFinished
            ? $race->results()
                ->where('result_type', $primaryContext['prediction_type'])
                ->when($primaryContext['prediction_type'] === 'stage', fn($query) => $query->where('stage_number', $primaryContext['stage_number']))
                ->where('position', 1)
                ->with('rider')
                ->first()
            : null;

        // AI topfavoriet (enkel voor renners die effectief starten)
        $topPrediction = $race->predictions()
            ->where('prediction_type', $primaryContext['prediction_type'])
            ->where('stage_number', $primaryContext['stage_number'])
            ->when($race->entries()->exists(), fn($q) =>
                $q->whereIn('rider_id', $race->entries()->pluck('rider_id'))
            )
            ->orderBy('predicted_position')
            ->with('rider')
            ->first();

        // Aantal renners: entries → resultaten → null
        $riderCount = $race->entries()->count()
            ?: $race->results()->distinct('rider_id')->count('rider_id')
            ?: null;

        // topPick: winnaar als race voorbij is, anders AI-favoriet
        $topPick = $winner?->rider?->full_name
            ?? $topPrediction?->rider?->full_name
            ?? '–';

        // Label voor de topPick
        $topPickLabel = $winner ? 'Winnaar' : ($topPrediction ? 'Model favoriet' : 'Topfavoriet');
        $raceTypeLabel = $race->isOneDay()
            ? ($this->isMonumentRace($race) ? 'Monument' : 'Eendagskoers')
            : 'Etappekoers';

        return [
            'slug'           => $race->pcs_slug,
            'name'           => $race->name,
            'category'       => $race->category ?? ucfirst(str_replace('_', ' ', $race->race_type)),
            'tier'           => $this->raceTier($race->category),
            'date'           => $race->start_date->locale('nl_BE')->translatedFormat('d M Y'),
            'summary'        => $this->parcoursDescription($race->parcours_type),
            'terrain'        => $this->terrainLabel($race->parcours_type),
            'terrain_key'    => strtolower((string) $race->parcours_type),
            'race_type'      => $raceTypeLabel,
            'rider_count'    => $riderCount,
            'is_finished'    => $isFinished,
            'is_live'        => $isLive,
            'has_prediction' => $topPrediction !== null,
            'win_probability'=> $topPrediction ? round($topPrediction->win_probability * 100, 1) : null,
            'topPick'        => $topPick,
            'topPickLabel'   => $topPickLabel,
        ];
    }

    private function isMonumentRace(Race $race): bool
    {
        return in_array($race->pcs_slug, self::MONUMENT_SLUGS, true);
    }

    private function raceTier(?string $category): string
    {
        $value = strtolower((string) $category);

        if (str_contains($value, 'uwt') || str_contains($value, 'worldtour')) {
            return 'WorldTour';
        }

        if (str_contains($value, '.pro') || str_contains($value, 'proseries')) {
            return 'ProSeries';
        }

        if (preg_match('/(^|\s)1\./', $value) === 1) {
            return '1.1/1.2';
        }

        if (preg_match('/(^|\s)2\./', $value) === 1) {
            return '2.1/2.2';
        }

        return 'Overig';
    }

    private function parcoursDescription(string $type): string
    {
        return match($type) {
            'flat'     => 'Vlak parcours waarbij sprintersploegen het tempo bepalen en de finale vaak in een massasprint eindigt.',
            'hilly'    => 'Heuvelachtig parcours met herhaalde korte beklimmingen die sterke punchers bevoordelen.',
            'mountain' => 'Bergrit met zware cols waar klimmers het verschil maken en het klassement beslist wordt.',
            'tt'       => 'Tijdrit waarbij elke renner solo rijdt en de klok de enige tegenstander is.',
            'classic'  => 'Klassieker met een mix van terrein, kasseien of hellingen die een allround kampioen vereisen.',
            default    => 'Gevarieerd parcours met meerdere terreinsoorten.',
        };
    }

    private function terrainLabel(?string $type): string
    {
        return match ($type) {
            'flat' => 'Vlak',
            'hilly' => 'Heuvels',
            'mountain' => 'Bergen',
            'cobbled' => 'Kasseien',
            'classic' => 'Klassieker',
            'tt' => 'Tijdrit',
            'mixed' => 'Gemengd',
            default => ucfirst((string) $type),
        };
    }

    private function formatTimestamp($value): ?string
    {
        return $value?->copy()->timezone('Europe/Brussels')->locale('nl_BE')->translatedFormat('d M H:i');
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

    private function formatPredictionGroups(Race $race, $predictions, array $primaryContext): array
    {
        $stages = is_array($race->stages_json) ? $race->stages_json : [];

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
                    'subtitle'   => null,
                    'is_primary' => $first->prediction_type === $primaryContext['prediction_type']
                        && (int) $first->stage_number === (int) $primaryContext['stage_number'],
                    'predictions' => $group
                        ->sortBy('predicted_position')
                        ->take(10)
                        ->map(fn($prediction) => [
                            'position'          => $prediction->predicted_position,
                            'rider_slug'        => $prediction->rider->pcs_slug,
                            'rider'             => $prediction->rider->full_name,
                            'team'              => $prediction->rider->team?->name ?? '–',
                            'win_probability'   => round($prediction->win_probability * 100, 1),
                            'top10_probability' => round($prediction->top10_probability * 100, 1),
                            'confidence'        => round($prediction->confidence_score * 100, 0),
                        ])
                        ->values()
                        ->toArray(),
                ];
            })
            ->map(function (array $payload) use ($stages) {
                [$type, $stageNumber] = explode(':', (string) ($payload['key'] ?? 'result:0')) + [null, 0];
                if ($type !== 'stage') {
                    return $payload;
                }

                $stageNr = (int) $stageNumber;
                $stage = collect($stages)->first(fn ($s) => (int) ($s['number'] ?? 0) === $stageNr) ?? [];

                $subtype = (string) ($stage['stage_subtype'] ?? '');
                $parcours = (string) ($stage['parcours_type'] ?? '');

                $label = match (true) {
                    $subtype === 'sprint' => 'Sprintetappe',
                    $subtype === 'reduced_sprint' => 'Heuvel / punch',
                    $subtype === 'summit_finish' => 'Bergetappe (aankomst bergop)',
                    $subtype === 'high_mountain' => 'Hoge bergen',
                    $subtype === 'tt' => 'Tijdrit',
                    $subtype === 'ttt' => 'Ploegentijdrit',
                    $parcours === 'flat' => 'Vlakke etappe',
                    $parcours === 'hilly' => 'Heuveletappe',
                    $parcours === 'mountain' => 'Bergetappe',
                    default => 'Gemengde etappe',
                };

                $payload['subtitle'] = $label;
                return $payload;
            })
            ->values()
            ->all();
    }
}
