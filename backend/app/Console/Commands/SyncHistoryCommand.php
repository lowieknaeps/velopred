<?php

namespace App\Console\Commands;

use App\Models\Race;
use App\Services\CalendarSyncService;
use App\Services\ExternalCyclingApiService;
use App\Services\RaceSyncService;
use App\Services\RiderSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncHistoryCommand extends Command
{
    protected $signature = 'sync:history
        {from=2019 : Startjaar}
        {to?      : Eindjaar (standaard: huidig jaar)}
        {--dry-run : Toon wat gesynchroniseerd zou worden zonder het te doen}';

    protected $description = 'Synchroniseer historische race-resultaten van een reeks jaren (2019–heden)';

    public function handle(): int
    {
        $from = (int) $this->argument('from');
        $to   = (int) ($this->argument('to') ?? date('Y'));

        if ($from > $to) {
            $this->error("'from' ({$from}) moet kleiner zijn dan 'to' ({$to}).");
            return self::FAILURE;
        }

        $isDryRun = $this->option('dry-run');

        $this->info("🚴 Historische sync: {$from} → {$to}" . ($isDryRun ? ' [DRY RUN]' : ''));

        $api          = new ExternalCyclingApiService();
        $calendarSync = new CalendarSyncService($api);
        $raceSync     = new RaceSyncService($api, new RiderSyncService($api));

        $totalSynced = 0;
        $totalSkipped = 0;
        $totalErrors  = 0;

        for ($year = $from; $year <= $to; $year++) {
            $this->line('');
            $this->info("── {$year} ──────────────────────────────");

            // Stap 1: kalender ophalen voor dit jaar
            $this->line("  📅 Kalender synchroniseren...");
            try {
                $result = $calendarSync->syncCalendar($year);
                $this->line("     {$result['new']} nieuw, {$result['updated']} bijgewerkt ({$result['total']} totaal)");
            } catch (\Throwable $e) {
                $this->error("  ❌ Kalender mislukt: " . $e->getMessage());
                continue;
            }

            // Stap 2: races synchroniseren die afgelopen zijn en nog geen resultaten hebben
            $races = Race::where('year', $year)
                ->where('end_date', '<=', now()->toDateString())
                ->whereDoesntHave('results')
                ->orderBy('start_date')
                ->get();

            $this->line("  🏁 {$races->count()} races te synchroniseren...");

            $bar = $this->output->createProgressBar($races->count());
            $bar->setFormat('  %current%/%max% [%bar%] %percent%% — %message%');
            $bar->start();

            foreach ($races as $race) {
                $bar->setMessage($race->name);

                if ($isDryRun) {
                    $bar->advance();
                    $totalSynced++;
                    continue;
                }

                try {
                    // withStartlist=false: historische syncs zijn sneller zonder startlijst
                    // Renners bestaan al via sync:teams; onbekende renners worden overgeslagen
                    $raceSync->syncRace($race->pcs_slug, $year, withStartlist: false);
                    $totalSynced++;
                } catch (\Throwable $e) {
                    Log::warning("[SyncHistory] Fout bij {$race->name} {$year}: " . $e->getMessage());
                    $totalErrors++;
                }

                $bar->advance();
            }

            $bar->finish();
            $this->line('');
        }

        $this->line('');
        $this->info("✅ Historische sync klaar!");
        $this->table(
            ['Gesynchroniseerd', 'Overgeslagen', 'Fouten'],
            [[$totalSynced, $totalSkipped, $totalErrors]]
        );

        if ($isDryRun) {
            $this->warn('Dit was een dry-run. Gebruik zonder --dry-run om echt te synchroniseren.');
        }

        return self::SUCCESS;
    }
}
