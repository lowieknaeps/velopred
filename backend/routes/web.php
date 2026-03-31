<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\PredictionController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RaceController;
use App\Http\Controllers\RiderController;
use App\Http\Controllers\WatchlistController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Publieke routes
Route::get('/', [HomeController::class, 'index']);

Route::get('/races', [RaceController::class, 'index']);
Route::get('/races/{race}', [RaceController::class, 'show']);
Route::post('/races/{race}/rerun-model', [RaceController::class, 'rerunModel']);
Route::get('/races/{race}/rerun-model/status', [RaceController::class, 'rerunModelStatus']);

Route::get('/riders', [RiderController::class, 'index']);
Route::get('/riders/{rider}', [RiderController::class, 'show']);

Route::get('/predictions', [PredictionController::class, 'index']);
Route::get('/over-mij', fn () => Inertia::render('About'))->name('about');

// Dashboard (Breeze)
Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

// Auth routes (profiel, watchlist)
Route::middleware('auth')->group(function () {
    // Profiel (Breeze)
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Watchlist
    Route::get('/watchlist', [WatchlistController::class, 'index'])->name('watchlist.index');
    Route::post('/watchlist/{rider}/toggle', [WatchlistController::class, 'toggle'])->name('watchlist.toggle');
    Route::post('/watchlist/{rider}', [WatchlistController::class, 'store'])->name('watchlist.store');
    Route::delete('/watchlist/{rider}', [WatchlistController::class, 'destroy'])->name('watchlist.destroy');
});

require __DIR__.'/auth.php';
