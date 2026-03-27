import { Head } from '@inertiajs/react';
import { useState } from 'react';
import RiderCard from '../../Components/RiderCard';
import SectionHeading from '../../Components/SectionHeading';
import AppLayout from '../../Layouts/AppLayout';

export default function RidersIndex({ riders = [] }) {
    const [query, setQuery] = useState('');

    const normalizedQuery = query.trim().toLowerCase();
    const filteredRiders = normalizedQuery
        ? riders.filter((rider) => {
            const haystack = [
                rider.name,
                rider.team,
                rider.predictionRace,
                rider.modelFit,
                rider.profile,
            ].join(' ').toLowerCase();

            return haystack.includes(normalizedQuery);
        })
        : riders;

    const visibleRiders = normalizedQuery ? filteredRiders : filteredRiders.slice(0, 60);

    return (
        <AppLayout>
            <Head title="Renners" />

            <div className="space-y-10">
                <SectionHeading
                    eyebrow="Rennerprofielen"
                    title="Renners gesorteerd op actuele voorspellingen en seizoensvorm."
                    description="Bovenaan staan de renners met de sterkste komende voorspellingen. Via de zoekfilter kan je snel een specifieke renner of ploeg terugvinden."
                />

                <section className="vp-panel p-5 sm:p-6">
                    <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                        <div>
                            <div className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Zoekfilter</div>
                            <h2 className="mt-2 font-display text-2xl font-semibold tracking-tight text-slate-950">
                                Zoek op renner, ploeg of komende koers
                            </h2>
                        </div>

                        <div className="w-full max-w-xl">
                            <label htmlFor="rider-search" className="sr-only">Zoek renner</label>
                            <input
                                id="rider-search"
                                type="search"
                                value={query}
                                onChange={(event) => setQuery(event.target.value)}
                                placeholder="Bijvoorbeeld: Pogacar, Alpecin of Ronde van Vlaanderen"
                                className="w-full appearance-none rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-slate-950 [&::-webkit-search-cancel-button]:hidden [&::-webkit-search-decoration]:hidden"
                            />
                        </div>
                    </div>

                    <div className="mt-4 text-sm text-slate-500">
                        {normalizedQuery
                            ? `${filteredRiders.length} renners gevonden voor "${query}".`
                            : `Top ${visibleRiders.length} van ${riders.length} actieve renners uit 2026. Gebruik de filter om verder te zoeken.`}
                    </div>
                </section>

                <div className="grid gap-5 lg:grid-cols-3">
                    {visibleRiders.map((rider) => (
                        <RiderCard key={rider.slug} rider={rider} />
                    ))}
                </div>

                {normalizedQuery && filteredRiders.length === 0 && (
                    <div className="vp-panel p-8 text-center text-slate-500">
                        Geen renners gevonden voor deze zoekopdracht.
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
