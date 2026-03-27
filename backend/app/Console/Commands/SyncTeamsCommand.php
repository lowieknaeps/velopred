<?php

namespace App\Console\Commands;

use App\Services\ExternalCyclingApiService;
use App\Services\TeamSyncService;
use Illuminate\Console\Command;

class SyncTeamsCommand extends Command
{
    protected $signature   = 'sync:teams {year? : Jaar om te synchroniseren (standaard: huidig jaar)}';
    protected $description = 'Synchroniseer alle WorldTeam + ProTeam ploegen en renners';

    public function handle(): int
    {
        $year = (int) ($this->argument('year') ?? date('Y'));

        $this->info("🏁 Ploegen synchroniseren voor {$year}...");
        $this->warn("   (dit kan enkele minuten duren wegens rate limiting)");

        $service = new TeamSyncService(new ExternalCyclingApiService());

        try {
            $result = $service->syncAllTeams($year);

            $this->info("✅ Klaar!");
            $this->table(
                ['Ploegen', 'Renners'],
                [[$result['teams'], $result['riders']]]
            );
        } catch (\Throwable $e) {
            $this->error("❌ Fout: " . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
