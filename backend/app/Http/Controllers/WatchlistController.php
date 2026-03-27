<?php

namespace App\Http\Controllers;

use App\Models\Rider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WatchlistController extends Controller
{
    /**
     * Toon de watchlist van de ingelogde gebruiker.
     */
    public function index(): View
    {
        $riders = auth()->user()
            ->watchlist()
            ->with('team')
            ->orderBy('pcs_ranking')
            ->get();

        return view('watchlist.index', compact('riders'));
    }

    /**
     * Voeg een renner toe aan de watchlist.
     */
    public function store(Rider $rider): RedirectResponse
    {
        auth()->user()->watchlist()->syncWithoutDetaching([$rider->id]);

        return back()->with('success', "{$rider->first_name} {$rider->last_name} toegevoegd aan je watchlist.");
    }

    /**
     * Verwijder een renner uit de watchlist.
     */
    public function destroy(Rider $rider): RedirectResponse
    {
        auth()->user()->watchlist()->detach($rider->id);

        return back()->with('success', "{$rider->first_name} {$rider->last_name} verwijderd uit je watchlist.");
    }

    /**
     * Toggle: zit de renner al in de watchlist? Verwijder hem, anders voeg toe.
     */
    public function toggle(Rider $rider): RedirectResponse
    {
        $user = auth()->user();

        if ($user->watchlist()->where('rider_id', $rider->id)->exists()) {
            $user->watchlist()->detach($rider->id);
            $message = "{$rider->first_name} {$rider->last_name} verwijderd uit je watchlist.";
        } else {
            $user->watchlist()->attach($rider->id);
            $message = "{$rider->first_name} {$rider->last_name} toegevoegd aan je watchlist.";
        }

        return back()->with('success', $message);
    }
}
