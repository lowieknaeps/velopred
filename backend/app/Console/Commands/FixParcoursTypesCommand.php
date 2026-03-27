<?php

namespace App\Console\Commands;

use App\Models\Race;
use App\Services\CalendarSyncService;
use Illuminate\Console\Command;

/**
 * Corrigeert de parcours_type voor alle bekende races op basis van de curated map.
 * Nodig omdat CalendarSyncService vroeger altijd 'mixed' instelde.
 *
 * Gebruik: php artisan fix:parcours-types [--dry-run]
 */
class FixParcoursTypesCommand extends Command
{
    protected $signature = 'fix:parcours-types
        {--dry-run : Toon enkel wat gewijzigd zou worden, zonder op te slaan}';

    protected $description = 'Corrigeer parcours_type voor alle bekende races (E3=cobbled, Amstel=hilly, enz.)';

    public function handle(): int
    {
        $map    = CalendarSyncService::PARCOURS_MAP;
        $dryRun = $this->option('dry-run');

        $this->info('Parcours types corrigeren...');

        $fixed = 0;
        $skipped = 0;

        foreach ($map as $slug => $correctType) {
            $races = Race::where('pcs_slug', $slug)
                ->where('parcours_type', '!=', $correctType)
                ->get();

            foreach ($races as $race) {
                $old = $race->parcours_type;
                $this->line("  {$race->name} {$race->year}: {$old} → {$correctType}");

                if (!$dryRun) {
                    $race->update(['parcours_type' => $correctType]);
                }
                $fixed++;
            }

            $skipped += Race::where('pcs_slug', $slug)
                ->where('parcours_type', $correctType)
                ->count();
        }

        if ($dryRun) {
            $this->warn("Dry-run: {$fixed} records zouden worden gewijzigd.");
        } else {
            $this->info("✅ {$fixed} records gecorrigeerd, {$skipped} al correct.");
        }

        return self::SUCCESS;
    }
}
