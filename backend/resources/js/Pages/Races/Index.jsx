import { Head } from '@inertiajs/react';
import { Calendar, Flag, Filter, Route, Trophy } from 'lucide-react';
import { useMemo, useState } from 'react';
import EmptyState from '../../Components/EmptyState';
import RaceList from '../../Components/RaceList';
import AppLayout from '../../Layouts/AppLayout';

export default function RacesIndex({ highlights = [], ongoing = [], upcoming = [], recentPast = [], lastYear = [] }) {
    const [tierFilter, setTierFilter] = useState('all');
    const [typeFilter, setTypeFilter] = useState('all');
    const [query, setQuery] = useState('');

    const allRaces = useMemo(() => [...ongoing, ...upcoming, ...recentPast, ...lastYear], [ongoing, upcoming, recentPast, lastYear]);
    const availableTiers = useMemo(() => ['all', ...Array.from(new Set(allRaces.map((race) => race.tier).filter(Boolean)))], [allRaces]);

    const applyFilters = (races) =>
        races.filter((race) => {
            if (tierFilter !== 'all' && race.tier !== tierFilter) return false;
            if (typeFilter !== 'all' && race.race_type !== typeFilter) return false;
            if (query.trim()) {
                const needle = query.trim().toLowerCase();
                return `${race.name} ${race.category} ${race.tier} ${race.terrain}`.toLowerCase().includes(needle);
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

            <div className="space-y-8">
                <section className="vp-panel p-6 sm:p-8">
                    <div className="vp-pill inline-flex items-center gap-2"><Route size={14} /> Racekalender</div>
                    <h1 className="mt-4 font-display text-4xl font-semibold tracking-tight sm:text-5xl">
                        Koersen, context en favorieten in een flow
                    </h1>
                </section>

                <section className="grid gap-4 lg:grid-cols-3">
                    {highlights.map((item) => (
                        <article key={item.label} className="vp-panel p-5">
                            <div className="inline-flex items-center gap-2 text-xs uppercase tracking-[0.22em] text-slate-400">
                                <span className="vp-icon-box"><Trophy size={13} /></span>
                                {item.label}
                            </div>
                            <div className="mt-3 font-display text-3xl font-semibold">{item.value}</div>
                            <p className="mt-2 text-sm text-slate-300">{item.text}</p>
                        </article>
                    ))}
                </section>

                <section className="vp-panel p-5">
                    <div className="mb-4 inline-flex items-center gap-2 text-xs uppercase tracking-[0.22em] text-slate-400">
                        <span className="vp-icon-box"><Filter size={13} /></span>
                        Filters
                    </div>
                    <div className="grid gap-4 lg:grid-cols-[1fr_1fr_1.5fr]">
                        <div>
                            <label className="text-xs uppercase tracking-[0.22em] text-slate-400">Niveau</label>
                            <select value={tierFilter} onChange={(event) => setTierFilter(event.target.value)} className="mt-2 w-full rounded-xl border border-slate-700 bg-slate-900 px-3 py-2 text-sm text-slate-200">
                                {availableTiers.map((tier) => <option key={tier} value={tier}>{tier === 'all' ? 'Alle niveaus' : tier}</option>)}
                            </select>
                        </div>
                        <div>
                            <label className="text-xs uppercase tracking-[0.22em] text-slate-400">Type</label>
                            <select value={typeFilter} onChange={(event) => setTypeFilter(event.target.value)} className="mt-2 w-full rounded-xl border border-slate-700 bg-slate-900 px-3 py-2 text-sm text-slate-200">
                                <option value="all">Alle types</option>
                                <option value="Eendagskoers">Eendagskoers</option>
                                <option value="Etappekoers">Etappekoers</option>
                            </select>
                        </div>
                        <div>
                            <label className="text-xs uppercase tracking-[0.22em] text-slate-400">Zoeken</label>
                            <input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Bijv. Giro, Dauphine, Scheldeprijs..." className="mt-2 w-full rounded-xl border border-slate-700 bg-slate-900 px-3 py-2 text-sm text-slate-200 placeholder:text-slate-500" />
                        </div>
                    </div>
                </section>

                {filteredOngoing.length > 0 && <section className="space-y-4"><h2 className="inline-flex items-center gap-2 font-display text-2xl font-semibold"><Flag size={18} /> Nu bezig</h2><RaceList races={filteredOngoing} /></section>}
                {filteredUpcoming.length > 0 && <section className="space-y-4"><h2 className="inline-flex items-center gap-2 font-display text-2xl font-semibold"><Calendar size={18} /> Komende wedstrijden</h2><RaceList races={filteredUpcoming} /></section>}
                {filteredRecentPast.length > 0 && <section className="space-y-4"><h2 className="font-display text-2xl font-semibold">Afgelopen dit seizoen</h2><RaceList races={filteredRecentPast} /></section>}
                {filteredLastYear.length > 0 && <section className="space-y-4"><h2 className="font-display text-2xl font-semibold">Vorig seizoen</h2><RaceList races={filteredLastYear} /></section>}

                {filteredOngoing.length === 0 && filteredUpcoming.length === 0 && filteredRecentPast.length === 0 && filteredLastYear.length === 0 && (
                    <EmptyState title="Geen koersen gevonden" message="Pas je filters aan of probeer een andere zoekterm." />
                )}
            </div>
        </AppLayout>
    );
}
