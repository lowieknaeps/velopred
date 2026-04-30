<?php

namespace App\Console\Commands;

use App\Models\Race;
use App\Services\ExternalCyclingApiService;
use App\Services\RaceSyncService;
use App\Services\RiderSyncService;
use Illuminate\Console\Command;

class SyncStageCommand extends Command
{
    protected $signature = 'sync:stage {slug} {year} {stage : PCS stage number (use 0 for prologue)} {--display= : Optional display stage number (default = stage, except prologue -> 1)}';
    protected $description = 'Synchroniseer 1 etappe-uitslag (handig als sync:race faalt door PCS blocks).';

    public function handle(): int
    {
        $slug = (string) $this->argument('slug');
        $year = (int) $this->argument('year');
        $pcsStage = (int) $this->argument('stage');
        $displayStage = $this->option('display') !== null ? (int) $this->option('display') : ($pcsStage === 0 ? 1 : $pcsStage);

        $race = Race::where('pcs_slug', $slug)->where('year', $year)->first();
        if (!$race) {
            $this->error("Race niet gevonden: {$slug} {$year} (run eerst sync:calendar of sync:race meta)");
            return self::FAILURE;
        }

        $this->info("🚴 Syncing stage: {$slug} {$year} PCS stage={$pcsStage} -> display={$displayStage}");

        $api = new ExternalCyclingApiService();
        $service = new RaceSyncService($api, new RiderSyncService($api));

        $candidates = [$pcsStage];
        // Prologues are inconsistent: some races expose the prologue as stage-1 (not stage-0).
        if ($pcsStage === 0) {
            $candidates[] = 1;
        }

        $lastError = null;
        foreach (array_values(array_unique($candidates)) as $candidate) {
            try {
                $service->syncSingleStageResult($race, (int) $candidate, $displayStage);
                $this->info("✅ Etappe opgeslagen (PCS stage {$candidate})");
                return self::SUCCESS;
            } catch (\Throwable $e) {
                $lastError = $e;
            }
        }

        $this->error("❌ Fout: " . ($lastError?->getMessage() ?? 'Onbekend'));
        return self::FAILURE;
    }
}
