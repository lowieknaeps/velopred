<?php

namespace App\Console\Commands;

use App\Models\Race;
use App\Services\ExternalCyclingApiService;
use App\Services\RaceSyncService;
use App\Services\RiderSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class RepairStageResultsCommand extends Command
{
    protected $signature = 'repair:stage-results {slug} {year}
                            {--max-pcs=25 : Max PCS stage number to probe (0..N)}
                            {--continue-on-error : Keep going when a stage fails}';

    protected $description = 'Rebuild stage results mapping by probing PCS stage endpoints and ordering by date (fixes prologue/offset issues).';

    public function handle(): int
    {
        $slug = (string) $this->argument('slug');
        $year = (int) $this->argument('year');
        $maxPcs = (int) $this->option('max-pcs');
        $continueOnError = (bool) $this->option('continue-on-error');

        $race = Race::where('pcs_slug', $slug)->where('year', $year)->first();
        if (!$race) {
            $this->error("Race niet gevonden: {$slug} {$year}");
            return self::FAILURE;
        }

        $api = new ExternalCyclingApiService();
        $sync = new RaceSyncService($api, new RiderSyncService($api));

        $this->info("🔎 Probing PCS stage endpoints for {$slug} {$year} (0..{$maxPcs})...");

        $found = collect();
        for ($pcs = 0; $pcs <= $maxPcs; $pcs++) {
            try {
                $data = $api->getStageResult($slug, $year, $pcs);
                $date = $data['date'] ?? null;
                if (!$date) {
                    continue;
                }
                $found->push([
                    'pcs' => $pcs,
                    'date' => $date,
                    'parcours_type' => $data['parcours_type'] ?? null,
                    'stage_subtype' => $data['stage_subtype'] ?? null,
                ]);
                $this->line("  ✅ pcs={$pcs} date={$date}");
            } catch (\Throwable $e) {
                // Ignore not found; warn on other errors
                $msg = $e->getMessage();
                if (str_contains($msg, 'Etappe niet gevonden') || str_contains($msg, 'Niet gevonden op PCS')) {
                    continue;
                }
                $this->warn("  ⚠️ pcs={$pcs} failed: {$msg}");
                if (!$continueOnError) {
                    return self::FAILURE;
                }
            }
        }

        if ($found->isEmpty()) {
            $this->error('Geen etappes gevonden via PCS stage endpoints.');
            return self::FAILURE;
        }

        // Order by date, then pcs as tie-breaker. Assign display stage numbers 1..N.
        $ordered = $found
            ->sortBy(function (array $row) {
                // Stable ordering: date first, then pcs stage number.
                return sprintf('%s-%04d', (string) ($row['date'] ?? ''), (int) ($row['pcs'] ?? 0));
            })
            ->values();

        $this->info('🧩 Rebuilding stage results with normalized display stage numbers...');

        $display = 1;
        foreach ($ordered as $row) {
            $pcs = (int) $row['pcs'];
            $this->line("→ display={$display} <= pcs={$pcs} ({$row['date']})");
            try {
                $sync->syncSingleStageResult($race, $pcs, $display);
            } catch (\Throwable $e) {
                $this->warn("  ⚠️  display={$display} pcs={$pcs} failed: {$e->getMessage()}");
                if (!$continueOnError) {
                    return self::FAILURE;
                }
            }
            $display++;
        }

        $this->info("✅ Done. Stages rebuilt: " . ($display - 1));
        return self::SUCCESS;
    }
}
