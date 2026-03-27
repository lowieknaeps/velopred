<?php

namespace App\Console\Commands;

use App\Services\CalendarSyncService;
use App\Services\ExternalCyclingApiService;
use Illuminate\Console\Command;

class SyncCalendarCommand extends Command
{
    protected $signature   = 'sync:calendar {year? : Jaar om te synchroniseren (standaard: huidig jaar)}';
    protected $description = 'Synchroniseer de WorldTour + ProSeries kalender vanuit ProcyclingStats';

    public function handle(): int
    {
        $year = (int) ($this->argument('year') ?? date('Y'));

        $this->info("📅 Kalender synchroniseren voor {$year}...");

        $service = new CalendarSyncService(new ExternalCyclingApiService());

        try {
            $result = $service->syncCalendar($year);

            $this->info("✅ Klaar!");
            $this->table(
                ['Nieuw', 'Bijgewerkt', 'Totaal'],
                [[$result['new'], $result['updated'], $result['total']]]
            );
        } catch (\Throwable $e) {
            $this->error("❌ Fout: " . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
