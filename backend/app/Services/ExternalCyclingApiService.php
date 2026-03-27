<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * HTTP client voor de Python AI-service (FastAPI op poort 8000).
 * Alle methodes geven een array terug met de gescrapte data.
 */
class ExternalCyclingApiService
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.ai_service.url', 'http://localhost:8000'), '/');
    }

    // ── Race ──────────────────────────────────────────────────────────────────

    /**
     * Race metadata + etappelijst.
     * bv. getRace('tour-de-france', 2024)
     */
    public function getRace(string $slug, int $year): array
    {
        return $this->get("/scrape/race/{$slug}/{$year}");
    }

    /**
     * Startlijst van een wedstrijd.
     */
    public function getStartlist(string $slug, int $year): array
    {
        return $this->get("/scrape/race/{$slug}/{$year}/startlist");
    }

    /**
     * Top competitors van de PCS racepagina.
     */
    public function getTopCompetitors(string $slug, int $year): array
    {
        return $this->get("/scrape/race/{$slug}/{$year}/top-competitors");
    }

    /**
     * Uitslag van een specifieke etappe.
     */
    public function getStageResult(string $slug, int $year, int $stageNr): array
    {
        return $this->get("/scrape/race/{$slug}/{$year}/stage/{$stageNr}");
    }

    /**
     * Eindklassement van een etappekoers.
     */
    public function getGcResult(string $slug, int $year): array
    {
        return $this->get("/scrape/race/{$slug}/{$year}/gc");
    }

    public function getPointsResult(string $slug, int $year): array
    {
        return $this->get("/scrape/race/{$slug}/{$year}/points");
    }

    public function getKomResult(string $slug, int $year): array
    {
        return $this->get("/scrape/race/{$slug}/{$year}/kom");
    }

    public function getYouthResult(string $slug, int $year): array
    {
        return $this->get("/scrape/race/{$slug}/{$year}/youth");
    }

    /**
     * Uitslag van een eendagskoers.
     */
    public function getOneDayResult(string $slug, int $year): array
    {
        return $this->get("/scrape/race/{$slug}/{$year}/result");
    }

    // ── Rider ─────────────────────────────────────────────────────────────────

    /**
     * Renner profiel.
     */
    public function getRider(string $slug): array
    {
        return $this->get("/scrape/rider/{$slug}");
    }

    /**
     * Recente resultaten van een renner.
     * $season = null → alle beschikbare resultaten
     */
    public function getRiderResults(string $slug, ?int $season = null): array
    {
        $url = "/scrape/rider/{$slug}/results";
        if ($season) {
            $url .= "?season={$season}";
        }
        return $this->get($url);
    }

    // ── Predictions ───────────────────────────────────────────────────────────

    public function trainModel(): array
    {
        $response = Http::timeout(600)->post($this->baseUrl . '/predict/train');
        if (!$response->successful()) {
            throw new RuntimeException("Trainingsfout: " . $response->body());
        }
        return $response->json();
    }

    public function predictRace(
        string $slug,
        int $year,
        string $parcoursType,
        array $riders,
        string $predictionType = 'result',
        int $stageNumber = 0,
    ): array
    {
        $response = Http::timeout(60)->post($this->baseUrl . '/predict/race', [
            'race_slug'     => $slug,
            'year'          => $year,
            'parcours_type' => $parcoursType,
            'prediction_type' => $predictionType,
            'stage_number'  => $stageNumber,
            'riders'        => $riders,
        ]);
        if (!$response->successful()) {
            throw new RuntimeException("Voorspellingsfout: " . $response->body());
        }
        return $response->json();
    }

    public function modelStatus(): array
    {
        return $this->get('/predict/status');
    }

    // ── Calendar & Teams ─────────────────────────────────────────────────────

    /**
     * Volledige WorldTour + ProSeries kalender voor een jaar.
     */
    public function getCalendar(int $year): array
    {
        return $this->get("/scrape/calendar/{$year}");
    }

    /**
     * Lijst van alle WorldTeam + ProTeam ploegen voor een jaar.
     */
    public function getTeams(int $year): array
    {
        return $this->get("/scrape/teams/{$year}");
    }

    /**
     * Roster van één ploeg.
     */
    public function getTeamRoster(string $slug): array
    {
        return $this->get("/scrape/team/{$slug}");
    }

    // ── Intern ────────────────────────────────────────────────────────────────

    private function get(string $path): array
    {
        $response = Http::timeout(30)->get($this->baseUrl . $path);

        if ($response->status() === 404) {
            throw new RuntimeException("Niet gevonden op PCS: {$path}");
        }

        if (!$response->successful()) {
            throw new RuntimeException(
                "AI-service fout ({$response->status()}) voor {$path}: " . $response->body()
            );
        }

        return $response->json();
    }
}
