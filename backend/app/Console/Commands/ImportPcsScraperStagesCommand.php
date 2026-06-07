<?php

namespace App\Console\Commands;

use App\Models\Race;
use App\Models\RaceResult;
use App\Models\Rider;
use Illuminate\Console\Command;

class ImportPcsScraperStagesCommand extends Command
{
    private const RACE_SLUG_ALIASES = [
        // PCS keeps the public route under /race/dauphine, while the 2026 scraper
        // exports the rebranded race name as Tour Auvergne - Rhone-Alpes.
        'tour-auvergne-rhone-alpes' => 'dauphine',
        'tour-auvergne-rhône-alpes' => 'dauphine',
        'criterium-du-dauphine' => 'dauphine',
    ];

    protected $signature = 'import:pcs-stages
        {season : Season year (bv. 2026)}
        {--file=C:\\Users\\lowie\\pcs-scraper\\output\\races.json : Pad naar races.json}
        {--only= : Optionele pcs_slug filter (bv. giro-d-italia)}';

    protected $description = 'Importeer etappe- en eendagsuitslagen uit pcs-scraper races.json naar race_results.';

    public function handle(): int
    {
        $season = (int) $this->argument('season');
        $file = (string) $this->option('file');
        $only = $this->option('only') ? (string) $this->option('only') : null;

        if (!is_file($file)) {
            $this->error("Bestand niet gevonden: {$file}");
            return self::FAILURE;
        }

        $json = file_get_contents($file);
        if ($json === false) {
            $this->error("Kan bestand niet lezen: {$file}");
            return self::FAILURE;
        }

        $rows = json_decode($json, true);
        if (!is_array($rows)) {
            $this->error('JSON parse failed.');
            return self::FAILURE;
        }

        $processedRaces = 0;
        $importedRows = 0;
        $touchedRaceIds = [];

        foreach ($rows as $row) {
            $raceId = (string) ($row['id'] ?? '');
            if ($raceId === '' || str_contains($raceId, '-stage-')) {
                continue;
            }

            if (!str_ends_with($raceId, '-' . $season)) {
                continue;
            }

            $pcsSlug = preg_replace('/-' . preg_quote((string) $season, '/') . '$/', '', $raceId);
            if (!is_string($pcsSlug) || $pcsSlug === '') {
                continue;
            }

            $dbSlug = $this->normalizeRaceSlug($pcsSlug);

            if ($only !== null && !in_array($only, [$pcsSlug, $dbSlug], true)) {
                continue;
            }

            $race = Race::query()->where('pcs_slug', $dbSlug)->where('year', $season)->first();
            if (!$race) {
                $suffix = $dbSlug !== $pcsSlug ? " (alias: {$dbSlug})" : '';
                $this->warn("Race niet gevonden in DB: {$pcsSlug} {$season}{$suffix}");
                continue;
            }

            $stages = $row['stages'] ?? [];
            if (!is_array($stages) || count($stages) === 0) {
                continue;
            }

            $processedRaces++;

            foreach ($stages as $stage) {
                $stageId = (string) ($stage['id'] ?? '');
                if (!preg_match('/-stage-(\d+)$/', $stageId, $matches)) {
                    continue;
                }

                $stageNumber = (int) $matches[1];
                $timeRows = $stage['stageResults']['time'] ?? [];
                if (!is_array($timeRows)) {
                    continue;
                }

                $resultType = $race->isOneDay() ? 'result' : 'stage';
                $storedStageNumber = $race->isOneDay() ? null : $stageNumber;
                $existingRows = RaceResult::query()
                    ->where('race_id', $race->id)
                    ->where('result_type', $resultType)
                    ->where('stage_number', $storedStageNumber)
                    ->whereNotNull('position')
                    ->where('status', 'finished')
                    ->count();

                if ($existingRows > count($timeRows)) {
                    $this->warn("Bestaande volledige uitslag behouden: {$race->pcs_slug} stage {$stageNumber} ({$existingRows} > " . count($timeRows) . ")");
                    continue;
                }

                if ($race->isOneDay()) {
                    RaceResult::query()
                        ->where('race_id', $race->id)
                        ->where('result_type', 'result')
                        ->whereNull('stage_number')
                        ->delete();
                } else {
                    RaceResult::query()
                        ->where('race_id', $race->id)
                        ->where('result_type', 'stage')
                        ->where('stage_number', $stageNumber)
                        ->delete();
                }

                foreach ($timeRows as $item) {
                    $riderSlug = (string) ($item['participant'] ?? '');
                    $position = isset($item['position']) ? (int) $item['position'] : null;

                    if ($riderSlug === '' || $position === null || $position <= 0) {
                        continue;
                    }

                    $rider = Rider::query()->where('pcs_slug', $riderSlug)->first();
                    if (!$rider) {
                        continue;
                    }

                    RaceResult::query()->updateOrCreate(
                        [
                            'race_id' => $race->id,
                            'rider_id' => $rider->id,
                            'result_type' => $resultType,
                            'stage_number' => $storedStageNumber,
                        ],
                        [
                            'team_id' => null,
                            'position' => $position,
                            'status' => 'finished',
                            'time_seconds' => isset($item['time']) ? (int) $item['time'] : null,
                            'gap_seconds' => null,
                            'pcs_points' => null,
                            'uci_points' => null,
                            'synced_at' => now(),
                        ]
                    );

                    $importedRows++;
                    $touchedRaceIds[$race->id] = true;
                }
            }
        }

        if (!empty($touchedRaceIds)) {
            Race::query()
                ->whereIn('id', array_keys($touchedRaceIds))
                ->update(['synced_at' => now()]);
        }

        $this->info("Import klaar. races={$processedRaces}, stage_rows={$importedRows}");
        return self::SUCCESS;
    }

    private function normalizeRaceSlug(string $slug): string
    {
        return self::RACE_SLUG_ALIASES[$slug] ?? $slug;
    }
}
