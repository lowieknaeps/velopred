import { Head } from '@inertiajs/react';
import { useState } from 'react';
import EmptyState from '../../Components/EmptyState';
import RiderCard from '../../Components/RiderCard';
import AppLayout from '../../Layouts/AppLayout';

export default function RidersIndex({ riders = [] }) {
    const [query, setQuery] = useState('');

    const needle = query.trim().toLowerCase();
    const filtered = needle
        ? riders.filter((rider) => `${rider.name} ${rider.team} ${rider.predictionRace} ${rider.modelFit} ${rider.profile}`.toLowerCase().includes(needle))
        : riders;

    const visible = needle ? filtered : filtered.slice(0, 60);

    return (
        <AppLayout>
            <Head title="Renners" />

            <div className="space-y-8">
                <section className="vp-panel p-6 sm:p-8">
                    <div className="vp-pill">Rennerprofielen</div>
                    <h1 className="mt-4 font-display text-4xl font-semibold tracking-tight sm:text-5xl">
                        Renners op actuele modelrelevantie
                    </h1>
                    <p className="mt-4 max-w-3xl text-sm leading-7 text-slate-300">
                        Zoek op naam, ploeg of context en open snel de detailanalyse per renner.
                    </p>
                    <div className="mt-6">
                        <input
                            type="search"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder="Bijvoorbeeld: Pogacar, Visma, Giro..."
                            className="w-full rounded-xl border border-slate-700 bg-slate-900 px-4 py-3 text-sm text-slate-200 placeholder:text-slate-500"
                        />
                        <p className="mt-3 text-xs text-slate-400">
                            {needle ? `${filtered.length} renners gevonden voor "${query}".` : `Top ${visible.length} van ${riders.length} renners.`}
                        </p>
                    </div>
                </section>

                {visible.length > 0 ? (
                    <div className="grid gap-4 lg:grid-cols-3">
                        {visible.map((rider) => (
                            <RiderCard key={rider.slug} rider={rider} />
                        ))}
                    </div>
                ) : (
                    <EmptyState
                        title="Geen renners gevonden"
                        message="Probeer een andere zoekterm of wis je filter."
                    />
                )}
            </div>
        </AppLayout>
    );
}
