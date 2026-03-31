import { Link } from '@inertiajs/react';

const raceTypeShort = {
    Eendagskoers: 'Eendags',
    Etappekoers: 'Etappe',
};

function TerrainIcon({ terrain }) {
    const key = String(terrain || '').toLowerCase();
    const baseClass = 'h-4 w-4 text-slate-500';

    if (key === 'mountain') {
        return (
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" className={baseClass} aria-hidden="true">
                <path d="M3 19h18L14 7l-3 4-2-2-6 10Z" />
            </svg>
        );
    }

    if (key === 'cobbled') {
        return (
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" className={baseClass} aria-hidden="true">
                <rect x="3" y="13" width="5" height="4" rx="1" />
                <rect x="9.5" y="11" width="5" height="6" rx="1" />
                <rect x="16" y="13" width="5" height="4" rx="1" />
                <path d="M3 19h18" />
            </svg>
        );
    }

    if (key === 'tt') {
        return (
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" className={baseClass} aria-hidden="true">
                <circle cx="12" cy="12" r="8" />
                <path d="M12 12V8m0 4 3 2" />
            </svg>
        );
    }

    if (key === 'hilly') {
        return (
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" className={baseClass} aria-hidden="true">
                <path d="M3 17h18M4 17c2-5 5-7 8-4 2-4 5-5 8 4" />
            </svg>
        );
    }

    if (key === 'classic') {
        return (
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" className={baseClass} aria-hidden="true">
                <path d="M6 19h12M8 19V9l4-3 4 3v10" />
                <path d="M10 11h4" />
            </svg>
        );
    }

    return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" className={baseClass} aria-hidden="true">
            <path d="M4 12h16M12 4v16" />
        </svg>
    );
}

export default function RaceList({ races = [] }) {
    return (
        <div className="grid gap-5 lg:grid-cols-3">
            {races.map((race) => (
                <article
                    key={race.slug}
                    className="vp-panel group flex h-full flex-col justify-between p-6 transition duration-300 hover:-translate-y-1 hover:shadow-[0_32px_90px_-36px_rgba(15,23,42,0.35)]"
                >
                    <div className="space-y-4">

                        {/* Header row: categorie + datum + status badges */}
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="rounded-full bg-teal-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.22em] text-teal-700">
                                    {race.category}
                                </span>
                                {race.tier && (
                                    <span className="rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-slate-600">
                                        {race.tier}
                                    </span>
                                )}
                                {race.is_live && (
                                    <span className="rounded-full bg-red-50 px-2 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-red-600">
                                        Live
                                    </span>
                                )}
                                {race.is_finished && (
                                    <span className="rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">
                                        Afgelopen
                                    </span>
                                )}
                                {race.has_prediction && !race.is_finished && !race.is_live && (
                                    <span className="rounded-full bg-indigo-50 px-2 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-indigo-600">
                                        Voorspeld
                                    </span>
                                )}
                            </div>
                            <span className="text-sm font-semibold text-slate-500">{race.date}</span>
                        </div>

                        {/* Naam + omschrijving */}
                        <div>
                            <h3 className="break-words font-display text-2xl font-semibold tracking-tight text-slate-950">
                                {race.name}
                            </h3>
                            <p className="mt-2 break-words text-sm leading-6 text-slate-600">{race.summary}</p>
                        </div>
                    </div>

                    <div className="mt-8 space-y-5">

                        {/* Info grid: type + terrein + renners/winkans */}
                        <div className="grid grid-cols-3 gap-3">
                            <div className="min-w-0 rounded-2xl bg-slate-50 p-3">
                                <div className="text-xs uppercase tracking-[0.22em] text-slate-400">Type</div>
                                <div
                                    className="mt-1 truncate text-sm font-semibold leading-tight text-slate-900"
                                    title={race.race_type}
                                >
                                    {raceTypeShort[race.race_type] ?? race.race_type}
                                </div>
                            </div>
                            <div className="min-w-0 rounded-2xl bg-slate-50 p-3">
                                <div className="text-xs uppercase tracking-[0.22em] text-slate-400">Parcours</div>
                                <div
                                    className="mt-1 flex items-center gap-1 truncate text-sm font-semibold leading-tight text-slate-900"
                                    title={race.terrain}
                                >
                                    <TerrainIcon terrain={race.terrain_key ?? race.terrain} />
                                    <span className="truncate">{race.terrain}</span>
                                </div>
                            </div>
                            {race.win_probability != null ? (
                                <div className="min-w-0 rounded-2xl bg-indigo-50 p-3">
                                    <div className="text-xs uppercase tracking-[0.22em] text-indigo-500">Winkans</div>
                                    <div className="mt-1 text-sm font-semibold leading-tight text-indigo-900">
                                        {race.win_probability}%
                                    </div>
                                </div>
                            ) : race.rider_count != null ? (
                                <div className="min-w-0 rounded-2xl bg-slate-50 p-3">
                                    <div className="text-xs uppercase tracking-[0.22em] text-slate-400">Renners</div>
                                    <div className="mt-1 text-sm font-semibold leading-tight text-slate-900">{race.rider_count}</div>
                                </div>
                            ) : (
                                <div className="min-w-0 rounded-2xl bg-slate-50 p-3">
                                    <div className="text-xs uppercase tracking-[0.22em] text-slate-400">Renners</div>
                                    <div className="mt-1 text-sm font-semibold leading-tight text-slate-400">–</div>
                                </div>
                            )}
                        </div>

                        {/* Bottom row: topPick + link */}
                        <div className="flex items-center justify-between gap-4">
                            <div className="min-w-0">
                                <div className="text-xs uppercase tracking-[0.22em] text-slate-400">
                                    {race.topPickLabel ?? 'Topfavoriet'}
                                </div>
                                <div className="mt-1 break-words text-base font-semibold text-slate-900">
                                    {race.topPick}
                                </div>
                            </div>
                            <Link
                                href={`/races/${race.slug}`}
                                className="rounded-full border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 transition group-hover:border-slate-950 group-hover:bg-slate-950 group-hover:text-white"
                            >
                                Bekijk race
                            </Link>
                        </div>
                    </div>
                </article>
            ))}
        </div>
    );
}
