<?php

namespace App\Console\Commands;

use App\Models\Prediction;
use App\Models\Race;
use App\Services\ExternalCyclingApiService;
use App\Services\PredictionService;
use App\Services\RaceSyncService;
use App\Services\RiderSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class PredictRacesCommand extends Command
{
    protected $signature = 'predict:races {year : Season year}
                            {--only-missing : Skip contexts that already have predictions for the current model version}
                            {--train : Train het model eerst opnieuw voor het voorspelt}
                            {--max= : Max number of races to predict}
                            {--continue-on-error : Keep going when a race fails}';

    protected $description = 'Voorspel meerdere races (met resume support per race en per etappe).';

    public function handle(): int
    {
        $year = (int) $this->argument('year');
        $onlyMissing = (bool) $this->option('only-missing');
        $max = $this->option('max') !== null ? (int) $this->option('max') : null;
        $continueOnError = (bool) $this->option('continue-on-error');

        $api             = new ExternalCyclingApiService();
        $riderSync       = new RiderSyncService($api);
        $prediction      = app(PredictionService::class);
        $raceSyncService = new RaceSyncService($api, $riderSync);

        if ($this->option('train')) {
            $this->info('Model trainen...');
            try {
                $stats = $api->trainModel();
                $this->info("✅ Model getraind — MAE: {$stats['mae_cv']} posities ({$stats['samples']} samples)");
            } catch (\Throwable $e) {
                $this->error('❌ Training mislukt: ' . $e->getMessage());
                return self::FAILURE;
            }
        }

        $status = $api->modelStatus();
        if (!$status['trained']) {
            $this->error('❌ Model nog niet getraind. Gebruik --train om het model eerst te trainen.');
            return self::FAILURE;
        }

        $modelVersion = (string)($status['model_version'] ?? 'v1');

        $query = Race::relevant()
            ->where('year', $year)
            // include last few days, plus future races
            ->where('start_date', '>=', now()->subDays(14)->toDateString())
            ->orderBy('start_date')
            ->orderBy('pcs_slug');

        if ($max !== null) {
            $query->limit($max);
        }

        /** @var \Illuminate\Support\Collection<int, Race> $races */
        $races = $query->get();
        if ($races->isEmpty()) {
            $this->info('✅ Geen races gevonden om te voorspellen.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            "🏁 %d race(s) voorspellen voor %d (model %s)%s...",
            $races->count(),
            $year,
            $modelVersion,
            $onlyMissing ? ' (only-missing)' : ''
        ));

        $failed = [];

        foreach ($races as $race) {
            $this->line("   → {$race->name} {$race->year}");

            try {
                $this->line('     ↻ Startlijst synchroniseren...');
                $raceSyncService->syncStartlistOnly($race);
                $race->refresh();

                if (!$race->entries()->exists()) {
                    $this->warn('     ⚠️  Geen officiële startlijst beschikbaar, voorspelling overgeslagen');
                    continue;
                }

                if ($onlyMissing) {
                    $missing = $this->raceHasMissingPredictionContexts($race, $modelVersion);
                    if (!$missing) {
                        $this->line('     ↷ Skip: alles al voorspeld voor deze modelversie');
                        continue;
                    }
                }

                $n = $prediction->predictRace($race);
                $this->line("     ✅ {$n} voorspellingen opgeslagen");
            } catch (\Throwable $e) {
                $failed[] = $race->pcs_slug;
                $this->warn("     ⚠️  Mislukt: " . $e->getMessage());
                if (!$continueOnError) {
                    return self::FAILURE;
                }
            }
        }

        if (!empty($failed)) {
            $this->warn('⚠️  Sommige races zijn mislukt: ' . implode(', ', $failed));
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function raceHasMissingPredictionContexts(Race $race, string $modelVersion): bool
    {
        $contexts = $this->buildContexts($race);

        foreach ($contexts as $ctx) {
            $exists = Prediction::query()
                ->where('race_id', $race->id)
                ->where('prediction_type', $ctx['prediction_type'])
                ->where('stage_number', $ctx['stage_number'])
                ->where('model_version', $modelVersion)
                ->exists();

            if (!$exists) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keep in sync with PredictionService::buildPredictionContexts().
     *
     * @return Collection<int, array{prediction_type:string,stage_number:int,parcours_type:string}>
     */
    private function buildContexts(Race $race): Collection
    {
        if ($race->isOneDay()) {
            return collect([[
                'prediction_type' => 'result',
                'stage_number'    => 0,
                'parcours_type'   => (string) $race->parcours_type,
            ]]);
        }

        $stageContexts = collect($race->stages_json ?? [])
            ->map(function (array $stage) use ($race) {
                return [
                    'prediction_type' => 'stage',
                    'stage_number'    => (int) ($stage['number'] ?? 0),
                    'parcours_type'   => (string) ($stage['parcours_type'] ?? $race->parcours_type),
                ];
            })
            ->filter(fn (array $ctx) => $ctx['stage_number'] > 0)
            ->values();

        return $stageContexts
            ->concat(collect([
                ['prediction_type' => 'gc', 'stage_number' => 0, 'parcours_type' => (string) $race->parcours_type],
                ['prediction_type' => 'points', 'stage_number' => 0, 'parcours_type' => 'flat'],
                ['prediction_type' => 'kom', 'stage_number' => 0, 'parcours_type' => 'mountain'],
                ['prediction_type' => 'youth', 'stage_number' => 0, 'parcours_type' => (string) $race->parcours_type],
            ]))
            ->values();
    }
}

