<?php

use App\Jobs\AutoSyncFinishedRacesJob;
use Illuminate\Support\Facades\Schedule;

/**
 * Velopred scheduler
 *
 * Elk uur  → sync resultaten van afgelopen/lopende races en refresh startlijsten
 * Dagelijks → kalender bijwerken (nieuwe races toevoegen)
 * Wekelijks → alle ploegen + renners herschikken
 */

// Elk uur: sync resultaten, evalueer voorspellingen en refresh startlijsten inline
// zodat dit ook zonder aparte queue worker blijft werken.
Schedule::call(fn () => app()->call([app(AutoSyncFinishedRacesJob::class), 'handle']))
    ->hourly()
    ->name('auto-sync-finished-races')
    ->withoutOverlapping();

// Voor races die morgen of vandaag starten: startlijst en voorspellingen
// vaker verversen zodat late startlist-wijzigingen zichtbaar blijven.
Schedule::call(fn () => app()->call([app(AutoSyncFinishedRacesJob::class), 'handleImminentStartlists']))
    ->everyFifteenMinutes()
    ->name('auto-sync-imminent-startlists')
    ->withoutOverlapping();

// Elke dag om 06:00: kalender bijwerken voor dit jaar
Schedule::command('sync:calendar', [date('Y')])
    ->dailyAt('06:00')
    ->name('sync-calendar')
    ->withoutOverlapping();

// Elke maandag om 07:00: alle ploegen + renners opnieuw synchroniseren
Schedule::command('sync:teams', [date('Y')])
    ->weekly()
    ->mondays()
    ->at('07:00')
    ->name('sync-teams')
    ->withoutOverlapping();
