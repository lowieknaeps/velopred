<?php

namespace App\Services;

use App\Models\Rider;
use App\Models\RiderResult;
use Illuminate\Support\Facades\Log;

class RiderResultsSyncService
{
    public function __construct(
        private ExternalCyclingApiService $api,
        private RiderSyncService $riderSync,
    ) {}

    public function syncRiderSeason(string $riderSlug, int $season): int
    {
        $rider = Rider::where('pcs_slug', $riderSlug)->first();
        if (!$rider) {
            $rider = $this->riderSync->syncRider($riderSlug);
        }

        Log::info("[RiderResultsSync] Syncing {$riderSlug} season={$season}");
        $data = $this->fetchRiderResultsWithAliases($riderSlug, $season);

        $rows = $data['results'] ?? [];
        $saved = 0;

        foreach ($rows as $row) {
            $date = null;
            if (!empty($row['date'])) {
                try {
                    $date = \Carbon\Carbon::parse($row['date'])->toDateString();
                } catch (\Throwable) {
                    $date = null;
                }
            }

            $attrs = [
                'rider_id' => $rider->id,
                'date' => $date,
                'race_name' => $row['race_name'] ?? null,
                'race_slug' => $row['race_slug'] ?? null,
                'race_url' => (string) ($row['race_url'] ?? ''),
                'race_class' => $row['race_class'] ?? null,
            ];

            if ($attrs['race_url'] === '') {
                continue;
            }

            $values = [
                'position' => isset($row['rank']) && is_numeric($row['rank']) ? (int) $row['rank'] : null,
                'status' => $row['status'] ?? 'finished',
                'pcs_points' => isset($row['pcs_points']) && is_numeric($row['pcs_points']) ? (int) $row['pcs_points'] : null,
                'uci_points' => isset($row['uci_points']) && is_numeric($row['uci_points']) ? (float) $row['uci_points'] : null,
                'season' => $season,
                'synced_at' => now(),
            ];

            RiderResult::updateOrCreate(
                [
                    'rider_id' => $attrs['rider_id'],
                    'race_url' => $attrs['race_url'],
                    'date' => $attrs['date'],
                ],
                array_merge($attrs, $values)
            );
            $saved++;
        }

        $rider->forceFill(['results_synced_at' => now()])->save();

        return $saved;
    }

    private function fetchRiderResultsWithAliases(string $slug, int $season): array
    {
        // PCS occasionally changes or aliases rider slugs (e.g. "tom-pidcock" vs "thomas-pidcock").
        // For results fetching we try a small alias set instead of hard-canonicalizing the DB slug.
        $aliasPairs = [
            'tom-pidcock' => 'thomas-pidcock',
            'thomas-pidcock' => 'tom-pidcock',
        ];

        $candidates = [$slug];
        if (isset($aliasPairs[$slug])) {
            $candidates[] = $aliasPairs[$slug];
        }

        foreach (array_values(array_unique($candidates)) as $candidate) {
            try {
                return $this->api->getRiderResults($candidate, $season);
            } catch (\RuntimeException $e) {
                // Try next alias on 404/not found.
                if (str_contains($e->getMessage(), 'Niet gevonden op PCS')) {
                    continue;
                }
                throw $e;
            }
        }

        // Re-throw a consistent error.
        throw new \RuntimeException("Niet gevonden op PCS: /scrape/rider/{$slug}/results?season={$season}");
    }
}
