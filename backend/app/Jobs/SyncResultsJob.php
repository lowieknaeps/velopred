<?php

namespace App\Jobs;

use App\Models\Race;
use App\Services\PostResultSyncService;
use App\Services\RaceSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Hersynct enkel de resultaten van een reeds bekende wedstrijd.
 * Handig na afloop van een race om de uitslag bij te werken.
 *
 * Gebruik:
 *   SyncResultsJob::dispatch($race);
 */
class SyncResultsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;
    public int $tries = 2;

    public function __construct(
        public readonly int $raceId,
    ) {}

    public function handle(RaceSyncService $service, PostResultSyncService $postResultSync): void
    {
        $race = Race::findOrFail($this->raceId);
        Log::info("[SyncResultsJob] Start: {$race->name} {$race->year}");
        $race = $service->syncRace($race->pcs_slug, $race->year);
        $postResultSync->handle($race);
        Log::info("[SyncResultsJob] Klaar: {$race->name} {$race->year}");
    }

    public function failed(\Throwable $e): void
    {
        Log::error("[SyncResultsJob] Mislukt voor race {$this->raceId}: {$e->getMessage()}");
    }
}
