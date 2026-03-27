<?php

namespace App\Console\Commands;

use App\Models\Race;
use App\Services\ExternalCyclingApiService;
use App\Services\RaceSyncService;
use App\Services\RiderSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ResyncStageRacesCommand extends Command
{
    protected $signature = 'resync:stage-races
        {from=2024 : Startjaar}
        {to? : Eindjaar (standaard: huidig jaar)}
        {--dry-run : Toon hoeveel rittenkoersen opnieuw gesynchroniseerd zouden worden}';

    protected $description = 'Hersynchroniseer rittenkoersen om GC- en nevenklassementen opnieuw op te bouwen';

    public function handle(): int
    {
        $from = (int) $this->argument('from');
        $to = (int) ($this->argument('to') ?? date('Y'));

        if ($from > $to) {
            $this->error("'from' ({$from}) moet kleiner zijn dan 'to' ({$to}).");
            return self::FAILURE;
        }

        $races = Race::relevant()
            ->where('race_type', 'stage_race')
            ->whereBetween('year', [$from, $to])
            ->where('start_date', '<=', now()->toDateString())
            ->orderBy('year')
            ->orderBy('start_date')
            ->get();

        $this->info("🔁 {$races->count()} rittenkoersen hersynchroniseren ({$from} → {$to})");

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $api = new ExternalCyclingApiService();
        $raceSync = new RaceSyncService($api, new RiderSyncService($api));
        $errors = 0;

        $bar = $this->output->createProgressBar($races->count());
        $bar->setFormat(' %current%/%max% [%bar%] %percent%% — %message%');
        $bar->start();

        foreach ($races as $race) {
            $bar->setMessage("{$race->year} {$race->name}");

            try {
                $raceSync->syncRace($race->pcs_slug, $race->year, withStartlist: false);
            } catch (\Throwable $e) {
                $errors++;
                Log::warning("[ResyncStageRaces] {$race->pcs_slug} {$race->year}: {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        if ($errors > 0) {
            $this->warn("⚠️ {$errors} rittenkoersen konden niet hersynchroniseerd worden.");
        } else {
            $this->info('✅ Alle rittenkoersen opnieuw gesynchroniseerd.');
        }

        return self::SUCCESS;
    }
}
