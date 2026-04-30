<?php

namespace App\Console\Commands;

use App\Models\Race;
use App\Services\ExternalCyclingApiService;
use App\Services\RaceSyncService;
use App\Services\RiderSyncService;
use Illuminate\Console\Command;

class SyncRacesCommand extends Command
{
    protected $signature = 'sync:races {year : Season year}
                            {--only-missing : Only sync races that have no startlist_synced_at yet}
                            {--max= : Max number of races to sync}
                            {--continue-on-error : Keep going when a race fails}';

    protected $description = 'Synchroniseer meerdere wedstrijden vanuit ProcyclingStats (met resume support).';

    public function handle(): int
    {
        $year = (int) $this->argument('year');
        $onlyMissing = (bool) $this->option('only-missing');
        $max = $this->option('max') !== null ? (int) $this->option('max') : null;
        $continueOnError = (bool) $this->option('continue-on-error');

        $query = Race::query()
            ->where('year', $year)
            ->relevant()
            ->orderBy('start_date')
            ->orderBy('pcs_slug');

        if ($onlyMissing) {
            // Resume-friendly: if we already synced the startlist at least once, skip it.
            $query->whereNull('startlist_synced_at');
        }

        if ($max !== null) {
            $query->limit($max);
        }

        $races = $query->get(['id', 'pcs_slug', 'name', 'year', 'start_date', 'startlist_synced_at']);

        if ($races->isEmpty()) {
            $this->info('✅ Niets te synchroniseren.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            "📅 %d race(s) synchroniseren voor %d%s...",
            $races->count(),
            $year,
            $onlyMissing ? ' (only-missing)' : ''
        ));

        $api = new ExternalCyclingApiService();
        $service = new RaceSyncService($api, new RiderSyncService($api));

        $failed = [];

        foreach ($races as $race) {
            $slug = (string) $race->pcs_slug;
            $this->line("🚴 Syncing: {$slug} {$year}");

            try {
                $service->syncRace($slug, $year);
                $this->info("✅ {$slug} gesynchroniseerd.");
            } catch (\Throwable $e) {
                $failed[] = $slug;
                $this->error("❌ {$slug} mislukt: " . $e->getMessage());

                if (!$continueOnError) {
                    $this->warn('Stoppen (gebruik --continue-on-error om door te gaan).');
                    return self::FAILURE;
                }
            }
        }

        if (!empty($failed)) {
            $this->warn('⚠️  Sommige races zijn mislukt: ' . implode(', ', $failed));
            return self::FAILURE;
        }

        $this->info('✅ Klaar.');
        return self::SUCCESS;
    }
}

