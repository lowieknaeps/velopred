<?php

namespace App\Services;

use App\Models\Race;
use Illuminate\Support\Facades\Log;

class CalendarSyncService
{
    /**
     * Curated parcours type per PCS slug.
     * Overschrijft de automatisch bepaalde waarde uit de kalender-scrape.
     * Gebaseerd op het vaste karakter van de koers (niet seizoensgebonden).
     */
    const PARCOURS_MAP = [
        // ── Kasseienklassiekers ───────────────────────────────────────────────
        'paris-roubaix'             => 'cobbled',
        'ronde-van-vlaanderen'      => 'cobbled',
        'e3-harelbeke'              => 'cobbled',
        'gent-wevelgem'             => 'cobbled',
        'dwars-door-vlaanderen'     => 'cobbled',
        'omloop-het-nieuwsblad'     => 'cobbled',
        'kuurne-brussel-kuurne'     => 'cobbled',
        'strade-bianche'            => 'cobbled',   // gravel + kasseien

        // ── Sprinterklassiekers ───────────────────────────────────────────────
        'classic-brugge-de-panne'   => 'flat',      // Brugge-De Panne
        'scheldeprijs'              => 'flat',
        'cyclassics-hamburg'        => 'flat',
        'copenhagen-sprint'         => 'flat',
        'renewi-tour'               => 'flat',

        // ── Heuvels / punchers ────────────────────────────────────────────────
        'amstel-gold-race'          => 'hilly',
        'la-fleche-wallonne'        => 'hilly',
        'eschborn-frankfurt'        => 'hilly',
        'gp-quebec'                 => 'hilly',
        'gp-montreal'               => 'hilly',
        'bretagne-classic'          => 'hilly',
        'san-sebastian'             => 'hilly',
        'in-flanders-fields'        => 'cobbled',

        // ── Grote klassiekers (all-round) ─────────────────────────────────────
        'milano-sanremo'            => 'classic',
        'liege-bastogne-liege'      => 'classic',
        'il-lombardia'              => 'classic',
        'world-championship'        => 'classic',   // WK wegrit

        // ── Tijdritten ────────────────────────────────────────────────────────
        'world-championship-itt'    => 'tt',

        // ── Grote ronden (bergklimkoersen) ────────────────────────────────────
        'tour-de-france'            => 'mountain',
        'giro-d-italia'             => 'mountain',
        'vuelta-a-espana'           => 'mountain',

        // ── Etappekoersen ─────────────────────────────────────────────────────
        'itzulia-basque-country'    => 'mountain',
        'volta-a-catalunya'         => 'mountain',
        'tour-de-romandie'          => 'mountain',
        'tour-de-suisse'            => 'mountain',
        'dauphine'                  => 'mountain',
        'tour-de-pologne'           => 'hilly',
        'tour-of-guangxi'           => 'mountain',
    ];

    /**
     * Extra gekende klassiekers buiten WorldTour/ProSeries die we expliciet
     * willen tonen in de kalender (indien beschikbaar op PCS).
     */
    const CURATED_SMALL_CLASSICS = [
        'de-brabantse-pijl'         => 'hilly',
        'brabantse-pijl'            => 'hilly',
        'scheldeprijs'              => 'flat',
        'nokere-koerse'             => 'flat',
        'gp-de-denain'              => 'cobbled',
        'brugge-de-panne'           => 'flat',
        'dwars-door-het-hageland'   => 'cobbled',
        'tro-bro-leon'              => 'cobbled',
        'le-samyn'                  => 'cobbled',
    ];

    public function __construct(
        private ExternalCyclingApiService $api
    ) {}

    /**
     * Synchroniseert de volledige WorldTour + ProSeries kalender voor een jaar.
     * Maakt nieuwe race-records aan, updatet bestaande.
     * Haalt geen resultaten op — dat doet AutoSyncFinishedRacesJob.
     *
     * @return array{new: int, updated: int, total: int}
     */
    public function syncCalendar(int $year): array
    {
        Log::info("[CalendarSync] Start kalender sync voor {$year}");

        $data  = $this->api->getCalendar($year);
        $races = $data['races'] ?? [];

        $new     = 0;
        $updated = 0;

        foreach ($races as $entry) {
            // Vrouwen-, junior- en beloftenwedstrijden overslaan
            if ($this->isIrrelevant($entry['name'])) {
                continue;
            }

            $isOneDay  = $entry['start_date'] === $entry['end_date'];
            $raceType  = $isOneDay ? 'one_day' : 'stage_race';

            $exists = Race::where('pcs_slug', $entry['pcs_slug'])
                ->where('year', $entry['year'])
                ->exists();

            $parcoursType = self::PARCOURS_MAP[$entry['pcs_slug']] ?? 'mixed';

            Race::updateOrCreate(
                [
                    'pcs_slug' => $entry['pcs_slug'],
                    'year'     => $entry['year'],
                ],
                [
                    'name'          => $entry['name'],
                    'start_date'    => $entry['start_date'],
                    'end_date'      => $entry['end_date'],
                    'category'      => $entry['category'],
                    'race_type'     => $raceType,
                    'parcours_type' => $parcoursType,
                ]
            );

            $exists ? $updated++ : $new++;
        }

        [$extraNew, $extraUpdated] = $this->syncCuratedSmallClassics($year);
        $new += $extraNew;
        $updated += $extraUpdated;

        Log::info("[CalendarSync] Klaar: {$new} nieuw, {$updated} bijgewerkt");

        return [
            'new'     => $new,
            'updated' => $updated,
            'total'   => count($races),
        ];
    }

    private function syncCuratedSmallClassics(int $year): array
    {
        $new = 0;
        $updated = 0;

        foreach (self::CURATED_SMALL_CLASSICS as $slug => $fallbackParcours) {
            try {
                $meta = $this->api->getRace($slug, $year);
            } catch (\RuntimeException) {
                continue;
            }

            $name = (string) ($meta['name'] ?? $slug);
            if ($this->isIrrelevant($name)) {
                continue;
            }

            $startDate = $meta['start_date'] ?? null;
            $endDate = $meta['end_date'] ?? $startDate;
            if (!$startDate || !$endDate) {
                continue;
            }

            $exists = Race::where('pcs_slug', $slug)
                ->where('year', $year)
                ->exists();

            $isOneDay = $startDate === $endDate;
            Race::updateOrCreate(
                [
                    'pcs_slug' => $slug,
                    'year' => $year,
                ],
                [
                    'name' => $name,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'country' => $meta['country'] ?? null,
                    'category' => $meta['category'] ?? '1.Pro',
                    'race_type' => $isOneDay ? 'one_day' : 'stage_race',
                    'parcours_type' => self::PARCOURS_MAP[$slug] ?? $fallbackParcours,
                    'stages_json' => $meta['stages'] ?? null,
                ]
            );

            $exists ? $updated++ : $new++;
        }

        if ($new > 0 || $updated > 0) {
            Log::info("[CalendarSync] Extra klassiekers: {$new} nieuw, {$updated} bijgewerkt");
        }

        return [$new, $updated];
    }

    /**
     * Bepaalt of een race niet relevant is voor dit platform
     * (vrouwen, junioren, beloften, mixed relay).
     */
    private function isIrrelevant(string $name): bool
    {
        $patterns = [' WE - ', ' WJ - ', ' WU - ', ' MJ - ', ' MU - ', 'Women', 'Mixed Relay'];
        foreach ($patterns as $pattern) {
            if (str_contains($name, $pattern)) return true;
        }
        return false;
    }
}
