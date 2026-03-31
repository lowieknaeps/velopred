import { Head } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import RaceList from '../../Components/RaceList';
import SectionHeading from '../../Components/SectionHeading';
import AppLayout from '../../Layouts/AppLayout';

export default function RacesIndex({ highlights = [], ongoing = [], upcoming = [], recentPast = [], lastYear = [] }) {
    const [tierFilter, setTierFilter] = useState('all');
    const [typeFilter, setTypeFilter] = useState('all');
    const [query, setQuery] = useState('');
    const allRaces = useMemo(
        () => [...ongoing, ...upcoming, ...recentPast, ...lastYear],
        [ongoing, upcoming, recentPast, lastYear]
    );
    const availableTiers = useMemo(() => {
        const tiers = Array.from(new Set(allRaces.map((race) => race.tier).filter(Boolean)));
        return ['all', ...tiers];
    }, [allRaces]);
    const applyFilters = (races) =>
        races.filter((race) => {
            if (tierFilter !== 'all' && race.tier !== tierFilter) {
                return false;
            }
            if (typeFilter !== 'all' && race.race_type !== typeFilter) {
                return false;
            }
            if (query.trim() !== '') {
                const needle = query.trim().toLowerCase();
                const haystack = `${race.name} ${race.category} ${race.tier} ${race.terrain}`.toLowerCase();
                return haystack.includes(needle);
            }
            return true;
        });

    const filteredOngoing = applyFilters(ongoing);
    const filteredUpcoming = applyFilters(upcoming);
    const filteredRecentPast = applyFilters(recentPast);
    const filteredLastYear = applyFilters(lastYear);

    return (
        <AppLayout>
            <Head title="Koersen" />

            <div className="space-y-12">
                <SectionHeading
                    eyebrow="Racekalender"
                    title="Van WorldTour tot kleinere klassiekers in één kalender."
                    description="Per koers zie je terreinprofiel, AI-topfavoriet en winkans, met extra focus op eendagsklassiekers buiten de grootste circuits."
                />

                {/* Highlights */}
                <div className="grid gap-4 lg:grid-cols-3">
                    {highlights.map((item) => (
                        <article key={item.label} className="vp-panel p-5">
                            <div className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">{item.label}</div>
                            <div className="mt-3 font-display text-3xl font-semibold tracking-tight text-slate-950">{item.value}</div>
                            <p className="mt-3 text-sm leading-6 text-slate-600">{item.text}</p>
                        </article>
                    ))}
                </div>

                <section className="vp-panel p-5">
                    <div className="grid gap-4 lg:grid-cols-[1fr_1fr_1.4fr]">
                        <div>
                            <label className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Niveau</label>
                            <select
                                value={tierFilter}
                                onChange={(event) => setTierFilter(event.target.value)}
                                className="mt-2 w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700"
                            >
                                {availableTiers.map((tier) => (
                                    <option key={tier} value={tier}>
                                        {tier === 'all' ? 'Alle niveaus' : tier}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Koerstype</label>
                            <select
                                value={typeFilter}
                                onChange={(event) => setTypeFilter(event.target.value)}
                                className="mt-2 w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700"
                            >
                                <option value="all">Alle types</option>
                                <option value="Eendagskoers">Eendagskoers</option>
                                <option value="Etappekoers">Etappekoers</option>
                            </select>
                        </div>
                        <div>
                            <label className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Zoeken</label>
                            <input
                                value={query}
                                onChange={(event) => setQuery(event.target.value)}
                                placeholder="Bijv. Brabantse Pijl, Scheldeprijs..."
                                className="mt-2 w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 placeholder:text-slate-400"
                            />
                        </div>
                    </div>
                </section>

                {/* Nu bezig */}
                {filteredOngoing.length > 0 && (
                    <section className="space-y-5">
                        <div className="flex items-center gap-3">
                            <span className="relative flex h-2.5 w-2.5">
                                <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-green-400 opacity-75" />
                                <span className="relative inline-flex h-2.5 w-2.5 rounded-full bg-green-500" />
                            </span>
                            <h2 className="font-display text-xl font-semibold text-slate-950">Nu bezig</h2>
                        </div>
                        <RaceList races={filteredOngoing} />
                    </section>
                )}

                {/* Komende wedstrijden */}
                {filteredUpcoming.length > 0 && (
                    <section className="space-y-5">
                        <div className="flex items-center justify-between">
                            <h2 className="font-display text-xl font-semibold text-slate-950">
                                Komende wedstrijden
                            </h2>
                            <span className="text-sm text-slate-400">{filteredUpcoming.length} races</span>
                        </div>
                        <RaceList races={filteredUpcoming} />
                    </section>
                )}

                {/* Afgelopen dit seizoen */}
                {filteredRecentPast.length > 0 && (
                    <section className="space-y-5">
                        <div className="flex items-center justify-between">
                            <h2 className="font-display text-xl font-semibold text-slate-950">
                                Afgelopen dit seizoen
                            </h2>
                            <span className="text-sm text-slate-400">{filteredRecentPast.length} races</span>
                        </div>
                        <RaceList races={filteredRecentPast} />
                    </section>
                )}

                {/* Vorig seizoen */}
                {filteredLastYear.length > 0 && (
                    <section className="space-y-5">
                        <div className="flex items-center justify-between">
                            <h2 className="font-display text-xl font-semibold text-slate-950">
                                Vorig seizoen
                            </h2>
                            <span className="text-sm text-slate-400">Ter referentie</span>
                        </div>
                        <RaceList races={filteredLastYear} />
                    </section>
                )}

                {filteredOngoing.length === 0 &&
                    filteredUpcoming.length === 0 &&
                    filteredRecentPast.length === 0 &&
                    filteredLastYear.length === 0 && (
                        <section className="vp-panel p-6 text-sm text-slate-600">
                            Geen koersen gevonden voor deze filters. Pas niveau, type of zoekterm aan.
                        </section>
                    )}
            </div>
        </AppLayout>
    );
}
