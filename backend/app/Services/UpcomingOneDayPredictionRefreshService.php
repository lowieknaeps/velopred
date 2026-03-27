<?php

namespace App\Services;

use App\Models\Race;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class UpcomingOneDayPredictionRefreshService
{
    public function __construct(
        private ExternalCyclingApiService $api,
        private RaceSyncService $raceSyncService,
        private PredictionService $predictionService,
    ) {}

    public function refresh(int $daysAhead = 30): int
    {
        $today = now()->toDateString();
        $until = now()->addDays($daysAhead)->toDateString();

        $races = Race::relevant()
            ->oneDay()
            ->whereDate('start_date', '>=', $today)
            ->whereDate('start_date', '<=', $until)
            ->orderBy('start_date')
            ->get();

        return $this->refreshRaces($races);
    }

    public function refreshDateRange(string $fromDate, string $untilDate): int
    {
        $races = Race::relevant()
            ->oneDay()
            ->whereDate('start_date', '>=', $fromDate)
            ->whereDate('start_date', '<=', $untilDate)
            ->orderBy('start_date')
            ->get();

        return $this->refreshRaces($races);
    }

    public function refreshRaces(Collection $races): int
    {
        $status = $this->api->modelStatus();
        if (!($status['trained'] ?? false)) {
            Log::warning('[PredictionRefresh] Model niet getraind, upcoming one-day refresh overgeslagen.');
            return 0;
        }

        $refreshed = 0;

        foreach ($races as $race) {
            try {
                $this->raceSyncService->syncStartlistOnly($race);
                $race->refresh();

                if (!$race->entries()->exists()) {
                    $race->predictions()
                        ->where('prediction_type', 'result')
                        ->where('stage_number', 0)
                        ->delete();
                    continue;
                }

                $this->predictionService->predictRace($race);
                $refreshed++;
            } catch (\Throwable $e) {
                Log::warning("[PredictionRefresh] {$race->name} mislukt: {$e->getMessage()}");
            }
        }

        Log::info("[PredictionRefresh] {$refreshed} upcoming one-day races vernieuwd.");

        return $refreshed;
    }
}
