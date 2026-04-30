<?php

namespace App\Jobs;

use App\Models\Race;
use App\Services\PostResultSyncService;
use App\Services\RaceSyncService;
use App\Services\UpcomingOneDayPredictionRefreshService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Draait elk uur via de scheduler en behandelt drie soorten races:
 *
 * 1. AFGELOPEN  (end_date < gisteren)
 *    → Sync resultaten als die er nog niet zijn
 *
 * 2. BEZIG      (start_date <= vandaag <= end_date)
 *    → Sync vandaag beschikbare etappe-uitslagen (nog niet gesyncte etappes)
 *      Elke dag opnieuw zodra de race nog loopt
 *
 * 3. BINNENKORT (start vandaag t/m +30 dagen)
 *    → Sync de startlijst zodat de website de deelnemers al toont
 *      en houd die automatisch vers tot aan de koers
 */
class AutoSyncFinishedRacesJob implements ShouldQueue
{
    use Queueable;

    public function handle(
        RaceSyncService $raceSyncService,
        PostResultSyncService $postResultSync,
        UpcomingOneDayPredictionRefreshService $predictionRefreshService,
    ): void
    {
        $now            = now();
        $today          = $now->toDateString();
        $yesterday      = $now->copy()->subDay()->toDateString();
        $in30Days       = $now->copy()->addDays(30)->toDateString();
        $startlistStale = $now->copy()->subHour();

        // ── 1. Afgelopen races ─────────────────────────────────────────────
        $finished = Race::whereDate('end_date', '<=', $yesterday)
            ->where(function ($q) {
                $q->whereNull('synced_at')
                  // SQLite and MySQL use different date arithmetic syntax.
                  ->orWhereRaw($this->staleAfterEndDateSql('+2 days'));
            })
            ->get();

        Log::info("[AutoSync] Afgelopen: {$finished->count()} races");

        $refreshUpcomingPredictions = false;

        foreach ($finished as $race) {
            try {
                $race = $raceSyncService->syncRace($race->pcs_slug, $race->year);
                $postSync = $postResultSync->handle($race, false);
                $refreshUpcomingPredictions = $refreshUpcomingPredictions || ($postSync['evaluated'] ?? false);
            } catch (\Throwable $e) {
                Log::warning("[AutoSync] Afgelopen race mislukt: {$race->name}: {$e->getMessage()}");
            }
        }

        // ── 2. Bezig ───────────────────────────────────────────────────────
        // Etappes van vandaag zijn beschikbaar vanaf ~18:00 op PCS.
        // We synchen als de race vandaag nog niet gesynchroniseerd is.
        $ongoing = Race::where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('synced_at')
                  ->orWhereRaw("date(synced_at) < ?", [$today]);
            })
            ->get();

        Log::info("[AutoSync] Bezig: {$ongoing->count()} races");

        foreach ($ongoing as $race) {
            try {
                $raceSyncService->syncRace($race->pcs_slug, $race->year);
                Log::info("[AutoSync] Bezig race gesynchroniseerd: {$race->name}");
            } catch (\Throwable $e) {
                Log::warning("[AutoSync] Bezig race mislukt: {$race->name}: {$e->getMessage()}");
            }
        }

        // ── 3. Binnenkort ──────────────────────────────────────────────────
        // Startlijst automatisch blijven verversen voor komende races.
        $upcoming = Race::where('start_date', '>=', $today)
            ->where('start_date', '<=', $in30Days)
            ->where(function ($q) use ($startlistStale) {
                $q->whereNull('startlist_synced_at')
                  ->orWhere('startlist_synced_at', '<', $startlistStale)
                  ->orWhereDoesntHave('entries');
            })
            ->get();

        Log::info("[AutoSync] Binnenkort: {$upcoming->count()} races (startlijst elk uur verversen)");

        foreach ($upcoming as $race) {
            try {
                $raceSyncService->syncStartlistOnly($race);
            } catch (\Throwable $e) {
                Log::warning("[AutoSync] Startlijst refresh mislukt: {$race->name}: {$e->getMessage()}");
            }
        }

        if ($refreshUpcomingPredictions) {
            $predictionRefreshService->refresh();
        }
    }

    public function handleImminentStartlists(
        RaceSyncService $raceSyncService,
        PostResultSyncService $postResultSync,
        UpcomingOneDayPredictionRefreshService $predictionRefreshService,
    ): void
    {
        $now = now();
        $today = now()->toDateString();
        $tomorrow = now()->copy()->addDay()->toDateString();
        $staleThreshold = now()->copy()->subMinutes(15);
        $sameDayResultWindow = $now->hour >= 17;

        $imminentRaces = Race::whereDate('start_date', '>=', $today)
            ->whereDate('start_date', '<=', $tomorrow)
            ->where(function ($q) use ($staleThreshold) {
                $q->whereNull('startlist_synced_at')
                    ->orWhere('startlist_synced_at', '<', $staleThreshold)
                    ->orWhereDoesntHave('entries');
            })
            ->orderBy('start_date')
            ->get();

        Log::info("[AutoSync] Imminent startlists: {$imminentRaces->count()} races");

        foreach ($imminentRaces as $race) {
            try {
                $raceSyncService->syncStartlistOnly($race);
            } catch (\Throwable $e) {
                Log::warning("[AutoSync] Imminent startlijst mislukt: {$race->name}: {$e->getMessage()}");
            }
        }

        // Zelfde dag resultaat-sync voor eendagskoersen (vanaf de avond).
        // Zo hoeven we niet te wachten tot "gisteren"-logica op de volgende dag.
        $evaluatedTodayFinishedOneDay = false;
        if ($sameDayResultWindow) {
            $todayFinishedOneDayRaces = Race::oneDay()
                ->whereDate('end_date', $today)
                ->orderBy('start_date')
                ->get();

            foreach ($todayFinishedOneDayRaces as $race) {
                $hasFinalResults = $race->results()
                    ->where('result_type', 'result')
                    ->whereNull('stage_number')
                    ->whereNotNull('position')
                    ->where('status', 'finished')
                    ->exists();

                $isStale = $race->synced_at === null || $race->synced_at->lt($staleThreshold);
                if (!$isStale && $hasFinalResults) {
                    continue;
                }

                try {
                    $syncedRace = $raceSyncService->syncRace($race->pcs_slug, $race->year);
                    $postSync = $postResultSync->handle($syncedRace, false);
                    $evaluatedTodayFinishedOneDay = $evaluatedTodayFinishedOneDay || ($postSync['evaluated'] ?? false);
                } catch (\Throwable $e) {
                    Log::warning("[AutoSync] Zelfde dag result-sync mislukt: {$race->name}: {$e->getMessage()}");
                }
            }
        }

        if ($imminentRaces->isNotEmpty() || $evaluatedTodayFinishedOneDay) {
            $predictionRefreshService->refreshDateRange($today, $tomorrow);
        }
    }

    private function staleAfterEndDateSql(string $offset): string
    {
        $driver = DB::connection()->getDriverName();

        // We want: synced_at < end_date + N days
        if ($driver === 'mysql') {
            // offset is expected like "+2 days"
            if (preg_match('/\\+(\\d+)\\s+days?/i', $offset, $m)) {
                $days = (int) $m[1];
                return "synced_at < DATE_ADD(end_date, INTERVAL {$days} DAY)";
            }
            // fallback: be conservative
            return "synced_at < end_date";
        }

        // SQLite: date(end_date, '+2 days')
        return "synced_at < date(end_date, '{$offset}')";
    }
}
