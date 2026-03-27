<?php

namespace App\Console\Commands;

use App\Models\Race;
use App\Services\ExternalCyclingApiService;
use App\Services\PredictionService;
use App\Services\RaceSyncService;
use App\Services\RiderSyncService;
use Illuminate\Console\Command;

class PredictRaceCommand extends Command
{
    protected $signature = 'predict:race
        {slug? : PCS slug van de race (bv. ronde-van-vlaanderen)}
        {year? : Jaar (standaard: huidig jaar)}
        {--train : Train het model eerst opnieuw voor het voorspelt}
        {--all   : Voorspel alle aankomende races van dit jaar}';

    protected $description = 'Genereer AI-voorspellingen voor een of alle komende races';

    public function handle(): int
    {
        $api             = new ExternalCyclingApiService();
        $riderSync       = new RiderSyncService($api);
        $prediction      = app(PredictionService::class);
        $raceSyncService = new RaceSyncService($api, $riderSync);

        // Optioneel: model (her)trainen
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

        // Check of model bestaat
        $status = $api->modelStatus();
        if (!$status['trained']) {
            $this->error('❌ Model nog niet getraind. Gebruik --train om het model eerst te trainen.');
            return self::FAILURE;
        }

        // Welke races voorspellen?
        if ($this->option('all')) {
            $races = Race::relevant()
                ->where('year', date('Y'))
                ->where('start_date', '>=', now()->subDays(3)->toDateString())
                ->orderBy('start_date')
                ->get();
        } else {
            $slug = $this->argument('slug');
            $year = (int) ($this->argument('year') ?? date('Y'));

            if (!$slug) {
                $this->error('Geef een race slug mee of gebruik --all.');
                return self::FAILURE;
            }

            $races = Race::relevant()
                ->where('pcs_slug', $slug)
                ->where('year', $year)
                ->get();

            if ($races->isEmpty()) {
                $this->error("Race '{$slug}' ({$year}) niet gevonden in de database.");
                return self::FAILURE;
            }
        }

        $this->info("🏁 {$races->count()} race(s) voorspellen...");

        foreach ($races as $race) {
            $this->line("   → {$race->name} {$race->year}");
            try {
                $this->line('     ↻ Startlijst synchroniseren...');
                $raceSyncService->syncStartlistOnly($race);
                $race->refresh();

                if (!$race->entries()->exists()) {
                    $race->predictions()->delete();
                    $this->warn('     ⚠️  Geen officiële startlijst beschikbaar, voorspelling overgeslagen');
                    continue;
                }

                $n = $prediction->predictRace($race);
                $this->line("     ✅ {$n} voorspellingen opgeslagen");
            } catch (\Throwable $e) {
                $this->warn("     ⚠️  Mislukt: " . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
