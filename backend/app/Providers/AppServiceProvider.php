<?php

namespace App\Providers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // SQLite can throw "database is locked" under concurrent reads/writes
        // (artisan predict runs + web requests). WAL + busy_timeout makes this
        // much more resilient without changing app logic.
        try {
            $driver = config('database.default');
            $connection = config("database.connections.{$driver}.driver");
            if ($connection === 'sqlite') {
                DB::statement('PRAGMA journal_mode=WAL;');
                DB::statement('PRAGMA synchronous=NORMAL;');
                DB::statement('PRAGMA busy_timeout=8000;');
            }
        } catch (\Throwable) {
            // Best-effort only.
        }
    }
}
