<?php

namespace App\Jobs;

use App\Services\RaceSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Synchroniseert één wedstrijd op de achtergrond.
 *
 * Gebruik:
 *   SyncRacesJob::dispatch('tour-de-france', 2024);
 */
class SyncRacesJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300; // max 5 min per race (veel scraping-requests)
    public int $tries = 2;

    public function __construct(
        public readonly string $slug,
        public readonly int    $year,
    ) {}

    public function handle(RaceSyncService $service): void
    {
        Log::info("[SyncRacesJob] Start: {$this->slug} {$this->year}");
        $service->syncRace($this->slug, $this->year);
        Log::info("[SyncRacesJob] Klaar: {$this->slug} {$this->year}");
    }

    public function failed(\Throwable $e): void
    {
        Log::error("[SyncRacesJob] Mislukt voor {$this->slug} {$this->year}: {$e->getMessage()}");
    }
}
