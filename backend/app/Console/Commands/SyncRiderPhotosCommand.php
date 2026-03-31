<?php

namespace App\Console\Commands;

use App\Models\Prediction;
use App\Models\Rider;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class SyncRiderPhotosCommand extends Command
{
    protected $signature = 'photos:sync-riders
        {--all : Neem alle renners (op basis van puntenranking)}
        {--limit=40 : Maximum aantal renners}
        {--force : Overschrijf bestaande lokale foto\'s}';

    protected $description = 'Zoek en download lokale rennersfoto\'s (Wikipedia), met fallback op PCS in de app.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $force = (bool) $this->option('force');
        $all = (bool) $this->option('all');

        $riders = $this->resolveRiders($limit, $all);
        if ($riders->isEmpty()) {
            $this->warn('Geen renners gevonden om foto\'s voor te synchroniseren.');
            return self::SUCCESS;
        }

        $dir = public_path('images/riders');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $downloaded = 0;
        $skipped = 0;
        $notFound = 0;

        $this->info("Foto-sync gestart voor {$riders->count()} renners...");
        $bar = $this->output->createProgressBar($riders->count());
        $bar->start();

        foreach ($riders as $rider) {
            $slug = (string) $rider->pcs_slug;
            if ($slug === '') {
                $bar->advance();
                continue;
            }

            if (!$force && $this->hasLocalPhoto($slug, $dir)) {
                $skipped++;
                $bar->advance();
                continue;
            }

            $imageUrl = $this->findWikipediaImageUrl($rider->full_name, $rider->last_name);
            if (!$imageUrl) {
                $notFound++;
                $bar->advance();
                continue;
            }

            try {
                $imageResponse = $this->wikiHttp(20)->get($imageUrl);
                if (!$imageResponse->successful()) {
                    $notFound++;
                    $bar->advance();
                    continue;
                }

                $extension = $this->guessExtension($imageUrl, $imageResponse->header('Content-Type'));
                $this->removeExistingPhotos($slug, $dir);

                file_put_contents("{$dir}/{$slug}.{$extension}", $imageResponse->body());
                $downloaded++;
            } catch (\Throwable) {
                $notFound++;
            }

            usleep(120000);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->table(
            ['Gedownload', 'Overgeslagen', 'Niet gevonden'],
            [[$downloaded, $skipped, $notFound]]
        );

        return self::SUCCESS;
    }

    private function resolveRiders(int $limit, bool $all): Collection
    {
        if ($all) {
            return Rider::query()
                ->whereNotNull('pcs_slug')
                ->orderByDesc('career_points')
                ->limit($limit)
                ->get(['id', 'pcs_slug', 'first_name', 'last_name']);
        }

        $today = now()->toDateString();
        $currentYear = (int) date('Y');

        $riderIds = Prediction::query()
            ->join('races', 'races.id', '=', 'predictions.race_id')
            ->where('races.year', $currentYear)
            ->where('races.start_date', '>=', $today)
            ->orderByDesc('predictions.win_probability')
            ->limit($limit * 4)
            ->pluck('predictions.rider_id')
            ->unique()
            ->take($limit)
            ->values();

        if ($riderIds->isEmpty()) {
            return Rider::query()
                ->whereNotNull('pcs_slug')
                ->orderByDesc('career_points')
                ->limit($limit)
                ->get(['id', 'pcs_slug', 'first_name', 'last_name']);
        }

        $orderMap = $riderIds->flip();

        return Rider::query()
            ->whereIn('id', $riderIds)
            ->get(['id', 'pcs_slug', 'first_name', 'last_name'])
            ->sortBy(fn (Rider $rider) => $orderMap[$rider->id] ?? PHP_INT_MAX)
            ->values();
    }

    private function findWikipediaImageUrl(string $fullName, ?string $lastName): ?string
    {
        $titles = $this->searchWikipediaTitles($fullName);
        $lastName = strtolower((string) $lastName);

        foreach ($titles as $title) {
            try {
                $summary = $this->wikiHttp(12)->get(
                    'https://en.wikipedia.org/api/rest_v1/page/summary/' . rawurlencode($title)
                );
                if (!$summary->successful()) {
                    continue;
                }

                $data = $summary->json();
                $extract = strtolower((string) ($data['extract'] ?? ''));
                $titleLower = strtolower((string) $title);

                if ($lastName !== '' && !str_contains($titleLower, $lastName)) {
                    continue;
                }

                if (
                    !str_contains($extract, 'cyclist') &&
                    !str_contains($extract, 'cycling') &&
                    !str_contains($extract, 'road bicycle')
                ) {
                    continue;
                }

                $imageUrl = $data['originalimage']['source'] ?? $data['thumbnail']['source'] ?? null;
                if (is_string($imageUrl) && $imageUrl !== '') {
                    return $imageUrl;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function searchWikipediaTitles(string $fullName): array
    {
        $queries = [
            "{$fullName} cyclist",
            "{$fullName} road cyclist",
            $fullName,
        ];

        $titles = [];

        foreach ($queries as $query) {
            try {
                $response = $this->wikiHttp(12)->get('https://en.wikipedia.org/w/api.php', [
                    'action' => 'query',
                    'list' => 'search',
                    'format' => 'json',
                    'utf8' => 1,
                    'srlimit' => 5,
                    'srsearch' => $query,
                ]);

                if (!$response->successful()) {
                    continue;
                }

                foreach (($response->json()['query']['search'] ?? []) as $result) {
                    $title = $result['title'] ?? null;
                    if (is_string($title) && $title !== '') {
                        $titles[] = $title;
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return array_values(array_unique($titles));
    }

    private function hasLocalPhoto(string $slug, string $dir): bool
    {
        foreach (['webp', 'jpg', 'jpeg', 'png'] as $extension) {
            if (is_file("{$dir}/{$slug}.{$extension}")) {
                return true;
            }
        }

        return false;
    }

    private function removeExistingPhotos(string $slug, string $dir): void
    {
        foreach (['webp', 'jpg', 'jpeg', 'png'] as $extension) {
            $path = "{$dir}/{$slug}.{$extension}";
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function guessExtension(string $url, ?string $contentType): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $candidate = strtolower(pathinfo((string) $path, PATHINFO_EXTENSION));
        if (in_array($candidate, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return $candidate;
        }

        $contentType = strtolower((string) $contentType);
        if (str_contains($contentType, 'png')) {
            return 'png';
        }
        if (str_contains($contentType, 'webp')) {
            return 'webp';
        }

        return 'jpg';
    }

    private function wikiHttp(int $timeoutSeconds)
    {
        return Http::timeout($timeoutSeconds)->withHeaders([
            'User-Agent' => 'Velopred/1.0 (student project; contact: local-dev)',
            'Accept' => 'application/json, image/*, */*',
        ]);
    }
}
