<?php

namespace App\Http\Controllers;

use App\Models\AdminSetting;
use App\Models\Prediction;
use App\Models\Race;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class AdminController extends Controller
{
    public function index(): Response
    {
        $this->ensureDefaultSettings();

        return Inertia::render('Admin/Index', [
            'stats' => $this->stats(),
            'settings' => AdminSetting::query()->orderBy('id')->get()->map(fn (AdminSetting $setting) => [
                'key' => $setting->key,
                'label' => $setting->label,
                'description' => $setting->description,
                'type' => $setting->type,
                'value' => $setting->typedValue(),
            ])->values(),
            'modelVersions' => $this->modelVersions(),
            'latestContexts' => $this->latestPredictionContexts(),
            'featureAudit' => $this->featureAudit(),
            'releaseChecks' => $this->releaseChecks(),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $this->ensureDefaultSettings();

        $validated = $request->validate([
            'settings' => ['required', 'array'],
            'settings.*' => ['nullable'],
        ]);

        $knownSettings = AdminSetting::query()->get()->keyBy('key');
        foreach (($validated['settings'] ?? []) as $key => $value) {
            $setting = $knownSettings->get($key);
            if (!$setting) {
                continue;
            }

            $setting->value = $setting->type === 'boolean'
                ? (filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0')
                : (is_scalar($value) ? trim((string) $value) : null);
            $setting->save();
        }

        return back()->with('status', 'Admin-instellingen opgeslagen.');
    }

    public function clearDashboardCache(): RedirectResponse
    {
        foreach (range(((int) date('Y')) - 1, ((int) date('Y')) + 1) as $year) {
            Cache::forget("home:dashboard:{$year}");
        }

        return back()->with('status', 'Dashboard-cache gewist.');
    }

    private function ensureDefaultSettings(): void
    {
        $defaults = [
            [
                'key' => 'jury_mode_enabled',
                'value' => '1',
                'type' => 'boolean',
                'label' => 'Jury-modus',
                'description' => 'Zet demo-context expliciet zichtbaar voor de verdediging.',
            ],
            [
                'key' => 'demo_race_slug',
                'value' => 'dauphine',
                'type' => 'string',
                'label' => 'Demo-koers slug',
                'description' => 'PCS slug van de koers die je tijdens de jury wil tonen.',
            ],
            [
                'key' => 'model_adjustment_note',
                'value' => 'v49: sprintcaps, TTT-groepering, stage-race diversiteit en afgeleide klassementen gecontroleerd.',
                'type' => 'text',
                'label' => 'Modelnotitie',
                'description' => 'Korte uitleg bij recente modelaanpassingen.',
            ],
            [
                'key' => 'data_quality_note',
                'value' => 'Startlijsten blijven kritisch: sync Copenhagen/Dauphine voor demo nog eens controleren.',
                'type' => 'text',
                'label' => 'Datakwaliteit-notitie',
                'description' => 'Aandachtspunt dat admin voor de demo wil onthouden.',
            ],
        ];

        foreach ($defaults as $default) {
            AdminSetting::query()->firstOrCreate(
                ['key' => $default['key']],
                $default
            );
        }
    }

    private function stats(): array
    {
        $tables = [
            'users' => 'Users',
            'races' => 'Koersen',
            'riders' => 'Renners',
            'race_entries' => 'Startlijstregels',
            'predictions' => 'Voorspellingen',
            'prediction_evaluations' => 'Evaluaties',
        ];

        return collect($tables)
            ->map(fn (string $label, string $table) => [
                'key' => $table,
                'label' => $label,
                'value' => Schema::hasTable($table) ? DB::table($table)->count() : 0,
            ])
            ->values()
            ->all();
    }

    private function modelVersions(): array
    {
        if (!Schema::hasTable('predictions')) {
            return [];
        }

        return Prediction::query()
            ->select('model_version', DB::raw('COUNT(*) as rows'), DB::raw('MAX(updated_at) as latest_update'))
            ->whereNotNull('model_version')
            ->groupBy('model_version')
            ->get()
            ->map(fn ($row) => [
                'model_version' => $row->model_version,
                'rows' => (int) $row->rows,
                'latest_update' => $row->latest_update,
            ])
            ->sortByDesc(function (array $row) {
                return preg_match('/^v(\d+)$/', (string) $row['model_version'], $matches)
                    ? (int) $matches[1]
                    : 0;
            })
            ->take(8)
            ->values()
            ->all();
    }

    private function latestPredictionContexts(): array
    {
        if (!Schema::hasTable('predictions') || !Schema::hasTable('races')) {
            return [];
        }

        return DB::table('predictions')
            ->join('races', 'races.id', '=', 'predictions.race_id')
            ->select([
                'races.name',
                'races.pcs_slug',
                'predictions.prediction_type',
                'predictions.stage_number',
                'predictions.model_version',
                DB::raw('COUNT(*) as rows'),
                DB::raw('MAX(predictions.updated_at) as latest_update'),
            ])
            ->groupBy('races.name', 'races.pcs_slug', 'predictions.prediction_type', 'predictions.stage_number', 'predictions.model_version')
            ->orderByDesc(DB::raw('MAX(predictions.updated_at)'))
            ->limit(12)
            ->get()
            ->map(fn ($row) => [
                'race' => $row->name,
                'slug' => $row->pcs_slug,
                'type' => $row->prediction_type,
                'stage_number' => (int) $row->stage_number,
                'model_version' => $row->model_version,
                'rows' => (int) $row->rows,
                'latest_update' => $row->latest_update,
            ])
            ->values()
            ->all();
    }

    private function featureAudit(): array
    {
        if (!Schema::hasTable('predictions')) {
            return ['sampled' => 0, 'keys' => []];
        }

        $latestVersion = $this->latestModelVersion();
        $query = Prediction::query()
            ->whereNotNull('features')
            ->latest('updated_at')
            ->limit(500);

        if ($latestVersion !== null) {
            $query->where('model_version', $latestVersion);
        }

        $counts = [];
        $sampled = 0;
        foreach ($query->get(['features']) as $prediction) {
            if (!is_array($prediction->features)) {
                continue;
            }

            $sampled++;
            foreach (array_keys($prediction->features) as $key) {
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        arsort($counts);

        return [
            'model_version' => $latestVersion,
            'sampled' => $sampled,
            'keys' => collect($counts)
                ->take(24)
                ->map(fn (int $count, string $key) => ['key' => $key, 'count' => $count])
                ->values()
                ->all(),
        ];
    }

    private function releaseChecks(): array
    {
        return [
            $this->dauphineCheck(),
            $this->copenhagenCheck(),
            $this->tourSprintCheck(),
        ];
    }

    private function latestModelVersion(): ?string
    {
        $versions = Prediction::query()
            ->whereNotNull('model_version')
            ->pluck('model_version');

        $best = null;
        $bestNumber = -1;
        foreach ($versions as $version) {
            if (preg_match('/^v(\d+)$/', (string) $version, $matches)) {
                $number = (int) $matches[1];
                if ($number > $bestNumber) {
                    $bestNumber = $number;
                    $best = (string) $version;
                }
            }
        }

        return $best;
    }

    private function dauphineCheck(): array
    {
        $race = Race::query()->where('pcs_slug', 'dauphine')->where('year', 2026)->first();
        if (!$race) {
            return $this->check('Dauphine v49 coverage', false, 'Koers niet gevonden.');
        }

        $contexts = Prediction::query()
            ->where('race_id', $race->id)
            ->select('prediction_type', 'stage_number', 'model_version', DB::raw('COUNT(*) as rows'))
            ->groupBy('prediction_type', 'stage_number', 'model_version')
            ->get();

        $stageContexts = $contexts->where('prediction_type', 'stage');
        $classificationTypes = $contexts->where('stage_number', 0)->pluck('prediction_type')->unique()->values();
        $allV49 = $contexts->isNotEmpty() && $contexts->every(fn ($row) => $row->model_version === 'v49');
        $hasStages = $stageContexts->pluck('stage_number')->unique()->count() >= 8;
        $hasClassifications = collect(['gc', 'points', 'kom', 'youth'])->every(fn ($type) => $classificationTypes->contains($type));

        return $this->check(
            'Dauphine v49 coverage',
            $allV49 && $hasStages && $hasClassifications,
            "{$stageContexts->pluck('stage_number')->unique()->count()} etappes, klassementen: {$classificationTypes->implode(', ')}"
        );
    }

    private function copenhagenCheck(): array
    {
        $race = Race::query()->where('pcs_slug', 'copenhagen-sprint')->where('year', 2026)->first();
        if (!$race) {
            return $this->check('Copenhagen startlist guard', false, 'Koers niet gevonden.');
        }

        $forbidden = ['tadej-pogacar', 'mathieu-van-der-poel'];
        $startlistHits = DB::table('race_entries')
            ->join('riders', 'riders.id', '=', 'race_entries.rider_id')
            ->where('race_entries.race_id', $race->id)
            ->whereIn('riders.pcs_slug', $forbidden)
            ->count();
        $predictionHits = DB::table('predictions')
            ->join('riders', 'riders.id', '=', 'predictions.rider_id')
            ->where('predictions.race_id', $race->id)
            ->whereIn('riders.pcs_slug', $forbidden)
            ->count();

        return $this->check(
            'Copenhagen startlist guard',
            $startlistHits === 0 && $predictionHits === 0,
            "Verboden renners in startlijst: {$startlistHits}, voorspellingen: {$predictionHits}"
        );
    }

    private function tourSprintCheck(): array
    {
        $race = Race::query()->where('pcs_slug', 'tour-de-france')->where('year', 2026)->first();
        if (!$race) {
            return $this->check('Tour sprint sanity', false, 'Tour 2026 niet gevonden.');
        }

        $topNames = DB::table('predictions')
            ->join('riders', 'riders.id', '=', 'predictions.rider_id')
            ->where('predictions.race_id', $race->id)
            ->where('predictions.prediction_type', 'stage')
            ->where('predictions.stage_number', 5)
            ->orderBy('predictions.predicted_position')
            ->limit(3)
            ->pluck('riders.full_name')
            ->implode(', ');

        $ok = str_contains(strtolower($topNames), 'philipsen')
            || str_contains(strtolower($topNames), 'merlier');

        return $this->check('Tour sprint sanity', $ok, $topNames ?: 'Geen stage 5 voorspelling.');
    }

    private function check(string $label, bool $ok, string $detail): array
    {
        return [
            'label' => $label,
            'status' => $ok ? 'ok' : 'warning',
            'detail' => $detail,
        ];
    }
}
