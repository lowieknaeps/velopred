<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

class SyncPcsResultsCommand extends Command
{
    protected $signature = 'sync:pcs-results
        {season? : Season year (default = huidig jaar)}
        {--java=C:\\Program Files\\Java\\jdk-17\\bin\\java.exe : Pad naar java executable}
        {--jar=C:\\Users\\lowie\\pcs-scraper\\release\\scraper.jar : Pad naar pcs-scraper jar}
        {--output=C:\\Users\\lowie\\pcs-scraper\\output : Output map van pcs-scraper}
        {--skip-cache : Forceer live fetch i.p.v. cache}';

    protected $description = 'Run pcs-scraper en importeer daarna automatisch resultaten naar Velopred.';

    public function handle(): int
    {
        $season = (int) ($this->argument('season') ?? now()->year);
        $javaPath = (string) $this->option('java');
        $jarPath = (string) $this->option('jar');
        $outputPath = (string) $this->option('output');

        if (!is_file($javaPath)) {
            $this->error("Java niet gevonden: {$javaPath}");
            return self::FAILURE;
        }

        if (!is_file($jarPath)) {
            $this->error("Scraper jar niet gevonden: {$jarPath}");
            return self::FAILURE;
        }

        $args = [
            $javaPath,
            '-jar',
            $jarPath,
            '--season',
            (string) $season,
            '--destination',
            $outputPath,
            '--formats',
            'json',
        ];

        if ((bool) $this->option('skip-cache')) {
            $args[] = '--skipCache';
        }

        $this->info("PCS scraper starten voor seizoen {$season}...");
        $run = Process::timeout(3600)->run($args);
        if ($run->failed()) {
            $this->error('PCS scraper gefaald.');
            $this->line($run->errorOutput() ?: $run->output());
            return self::FAILURE;
        }

        $jsonFile = rtrim($outputPath, '\\/') . DIRECTORY_SEPARATOR . 'races.json';
        $this->info("Importeren uit {$jsonFile}...");
        $exit = Artisan::call('import:pcs-stages', [
            'season' => $season,
            '--file' => $jsonFile,
        ]);
        $this->line(Artisan::output());

        if ($exit !== 0) {
            $this->error('Import gefaald.');
            return self::FAILURE;
        }

        $this->info('✅ PCS resultaten sync voltooid.');
        return self::SUCCESS;
    }
}

