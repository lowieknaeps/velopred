<?php

namespace App\Jobs;

use App\Models\Race;
use App\Services\ExternalCyclingApiService;
use App\Services\RaceSyncService;
use App\Services\RiderSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Synchroniseert startlijsten voor een lijst van komende races.
 * Zodat de website al kan tonen wie er rijdt vóór de race begint.
 */
class SyncStartlistsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;
    public int $tries   = 2;

    public function __construct(
        public readonly array $raceIds
    ) {}

    public function handle(): void
    {
        $service = new RaceSyncService(
            new ExternalCyclingApiService(),
            new RiderSyncService(new ExternalCyclingApiService()),
        );

        foreach ($this->raceIds as $raceId) {
            $race = Race::find($raceId);
            if (!$race) continue;

            try {
                Log::info("[SyncStartlists] Startlijst: {$race->name} ({$race->start_date->format('d/m')})");
                $service->syncStartlistOnly($race);
            } catch (\Throwable $e) {
                Log::warning("[SyncStartlists] Mislukt voor {$race->name}: {$e->getMessage()}");
            }
        }
    }
}
