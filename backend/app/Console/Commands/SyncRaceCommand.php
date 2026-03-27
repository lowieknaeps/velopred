<?php

namespace App\Console\Commands;

use App\Services\PostResultSyncService;
use App\Services\ExternalCyclingApiService;
use App\Services\RaceSyncService;
use App\Services\RiderSyncService;
use Illuminate\Console\Command;

class SyncRaceCommand extends Command
{
    protected $signature   = 'sync:race {slug} {year}';
    protected $description = 'Synchroniseer een wedstrijd vanuit ProcyclingStats';

    public function handle(): int
    {
        $slug = $this->argument('slug');
        $year = (int) $this->argument('year');

        $this->info("🚴 Syncing: {$slug} {$year}");

        $service = new RaceSyncService(
            new ExternalCyclingApiService(),
            new RiderSyncService(new ExternalCyclingApiService()),
        );

        try {
            $race = $service->syncRace($slug, $year);
            $postSync = app(PostResultSyncService::class)->handle($race);
            $this->info("✅ {$race->name} {$year} gesynchroniseerd.");
            $this->table(
                ['Races', 'Renners', 'Teams', 'Resultaten'],
                [[
                    \App\Models\Race::count(),
                    \App\Models\Rider::count(),
                    \App\Models\Team::count(),
                    \App\Models\RaceResult::count(),
                ]]
            );

            if ($postSync['evaluated'] ?? false) {
                $this->line("📊 Evaluatie opgeslagen en {$postSync['refreshed_upcoming_predictions']} upcoming eendagskoersen vernieuwd.");
            }
        } catch (\Throwable $e) {
            $this->error("❌ Fout: " . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
