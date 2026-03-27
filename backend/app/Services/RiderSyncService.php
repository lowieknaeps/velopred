<?php

namespace App\Services;

use App\Helpers\NameHelper;
use App\Models\Rider;
use App\Models\Team;
use Illuminate\Support\Facades\Log;

class RiderSyncService
{
    public function __construct(
        private ExternalCyclingApiService $api
    ) {}

    /**
     * Synchroniseert een renner op basis van zijn PCS slug.
     * Maakt aan als hij niet bestaat, updatet als hij al bestaat.
     *
     * @return Rider
     */
    public function syncRider(string $slug): Rider
    {
        Log::info("[RiderSync] Syncing rider: {$slug}");

        $data = $this->api->getRider($slug);
        $specialities = $data['specialities'] ?? [];

        // Zoek het team op (kan null zijn)
        $team = null;
        if (!empty($data['current_team_slug']) && $data['current_team_slug'] !== 'team') {
            $team = Team::firstOrCreate(
                ['pcs_slug' => $data['current_team_slug']],
                ['name'     => $data['current_team_name'] ?? $data['current_team_slug']]
            );
        }

        // Naam splitsen (PCS geeft volledige naam als één string)
        [$firstName, $lastName] = NameHelper::parse($data['name'] ?? '');

        $rider = Rider::updateOrCreate(
            ['pcs_slug' => $slug],
            [
                'first_name'    => $firstName,
                'last_name'     => $lastName,
                'nationality'   => $data['nationality'] ?? null,
                'date_of_birth' => $this->parseBirthdate($data['birthdate'] ?? null),
                'team_id'       => $team?->id,
                'pcs_speciality_one_day' => $this->parseSpeciality($specialities, 'one_day_races'),
                'pcs_speciality_gc' => $this->parseSpeciality($specialities, 'gc'),
                'pcs_speciality_tt' => $this->parseSpeciality($specialities, 'time_trial'),
                'pcs_speciality_sprint' => $this->parseSpeciality($specialities, 'sprint'),
                'pcs_speciality_climber' => $this->parseSpeciality($specialities, 'climber'),
                'pcs_speciality_hills' => $this->parseSpeciality($specialities, 'hills'),
                'pcs_weight_kg' => $data['weight'] ?? null,
                'pcs_height_m' => $data['height'] ?? null,
                'synced_at'     => now(),
                'profile_synced_at' => now(),
            ]
        );

        Log::info("[RiderSync] Synced: {$rider->full_name} (id: {$rider->id})");

        return $rider;
    }

    /**
     * Synchroniseert een renner vanuit startlijstdata (minder detail,
     * maar voldoende om de renner en zijn team aan te maken).
     */
    public function syncFromStartlistEntry(array $entry): Rider
    {
        return $this->syncFromRaceEntry($entry);
    }

    /**
     * Synchroniseert een renner vanuit de PCS top competitors tabel.
     */
    public function syncFromTopCompetitorEntry(array $entry): Rider
    {
        return $this->syncFromRaceEntry($entry);
    }

    private function syncFromRaceEntry(array $entry): Rider
    {
        // Team aanmaken / ophalen
        $team = null;
        if (!empty($entry['team_slug']) && $entry['team_slug'] !== 'team') {
            $team = Team::updateOrCreate(
                ['pcs_slug' => $entry['team_slug']],
                ['name'     => $entry['team_name'] ?? $entry['team_slug']]
            );
        }

        // Naam splitsen
        [$firstName, $lastName] = NameHelper::parse($entry['rider_name'] ?? '');

        $updates = [
            'first_name'  => $firstName,
            'last_name'   => $lastName,
            'team_id'     => $team?->id,
            'synced_at'   => now(),
        ];

        if (!empty($entry['nationality'])) {
            $updates['nationality'] = $entry['nationality'];
        }

        if (array_key_exists('pcs_ranking', $entry) && $entry['pcs_ranking'] !== null) {
            $updates['pcs_ranking'] = $entry['pcs_ranking'];
        }

        if (array_key_exists('career_points', $entry) && $entry['career_points'] !== null) {
            $updates['career_points'] = $entry['career_points'];
        }

        if (array_key_exists('age', $entry) && $entry['age'] !== null) {
            $updates['age_approx'] = $entry['age'];
        }

        $rider = Rider::updateOrCreate(
            ['pcs_slug' => $entry['rider_slug']],
            $updates
        );

        $needsProfile = !$rider->profile_synced_at
            || $rider->profile_synced_at->lt(now()->subDays(30))
            || $rider->pcs_speciality_sprint === null
            || $rider->pcs_speciality_climber === null
            || $rider->pcs_speciality_hills === null
            || $rider->pcs_speciality_tt === null;

        if ($needsProfile) {
            try {
                return $this->syncRider($entry['rider_slug']);
            } catch (\Throwable $e) {
                Log::warning("[RiderSync] Kon profiel niet verrijken voor {$entry['rider_slug']}: {$e->getMessage()}");
            }
        }

        return $rider;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Converteert PCS geboortedatumformaat "1998-9-21" naar "1998-09-21".
     */
    private function parseBirthdate(?string $raw): ?string
    {
        if (!$raw) return null;
        try {
            return \Carbon\Carbon::parse($raw)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseSpeciality(array $specialities, string $key): ?int
    {
        $value = $specialities[$key] ?? null;

        return is_numeric($value) ? (int) round((float) $value) : null;
    }
}
