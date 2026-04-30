<?php

namespace App\Console\Commands;

use App\Models\Race;
use App\Models\Rider;
use App\Services\ExternalCyclingApiService;
use App\Services\RiderResultsSyncService;
use App\Services\RiderSyncService;
use Illuminate\Console\Command;

class SyncRiderResultsCommand extends Command
{
    protected $signature = 'sync:rider-results
                            {slug? : PCS slug van de renner (bv. tadej-pogacar)}
                            {season? : Seizoen (bv. 2026)}
                            {--from-races= : Sync results for all riders that appear in race entries for the given year}
                            {--max= : Max number of riders}
                            {--continue-on-error : Keep going when a rider fails}';

    protected $description = 'Synchroniseer PCS resultaten (vorm) van renners naar rider_results.';

    public function handle(): int
    {
        $api = new ExternalCyclingApiService();
        $riderSync = new RiderSyncService($api);
        $service = new RiderResultsSyncService($api, $riderSync);

        $continueOnError = (bool) $this->option('continue-on-error');
        $max = $this->option('max') !== null ? (int) $this->option('max') : null;

        $fromRacesYear = $this->option('from-races') !== null ? (int) $this->option('from-races') : null;
        if ($fromRacesYear !== null) {
            $season = $this->argument('season') !== null ? (int) $this->argument('season') : $fromRacesYear;

            $riderSlugs = Rider::query()
                ->whereIn('id', function ($q) use ($fromRacesYear) {
                    $q->select('rider_id')
                        ->from('race_entries')
                        ->join('races', 'race_entries.race_id', '=', 'races.id')
                        ->where('races.year', $fromRacesYear)
                        ->whereNotNull('race_entries.rider_id');
                })
                ->orderBy('pcs_slug')
                ->pluck('pcs_slug');

            if ($max !== null) {
                $riderSlugs = $riderSlugs->take($max);
            }

            $this->info(sprintf("🔄 %d rider(s) results syncen (season=%d) op basis van race entries %d", $riderSlugs->count(), $season, $fromRacesYear));

            $failed = [];
            foreach ($riderSlugs as $slug) {
                $this->line("→ {$slug}");
                try {
                    $n = $service->syncRiderSeason($slug, $season);
                    $this->line("  ✅ {$n} resultaten opgeslagen");
                } catch (\Throwable $e) {
                    $failed[] = $slug;
                    $this->warn("  ⚠️  Mislukt: {$e->getMessage()}");
                    if (!$continueOnError) {
                        return self::FAILURE;
                    }
                }
            }

            if (!empty($failed)) {
                $this->warn('⚠️  Mislukt voor: ' . implode(', ', $failed));
                return self::FAILURE;
            }

            return self::SUCCESS;
        }

        $slug = $this->argument('slug');
        $season = (int) ($this->argument('season') ?? date('Y'));

        if (!$slug) {
            $this->error('Geef een rider slug mee, of gebruik --from-races=YYYY.');
            return self::FAILURE;
        }

        $n = $service->syncRiderSeason((string) $slug, $season);
        $this->info("✅ {$n} resultaten opgeslagen voor {$slug} ({$season})");
        return self::SUCCESS;
    }
}

