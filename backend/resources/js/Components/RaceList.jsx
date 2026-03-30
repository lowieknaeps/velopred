import { Link } from '@inertiajs/react';

const parcoursIcon = {
    flat:     '⬜',
    hilly:    '🔷',
    mountain: '⛰️',
    cobbled:  '🪨',
    classic:  '🏆',
    tt:       '⏱️',
    mixed:    '🔀',
};

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
                            <div className="rounded-2xl bg-slate-50 p-3">
                                <div className="text-xs uppercase tracking-[0.22em] text-slate-400">Type</div>
                                <div className="mt-1 text-sm font-semibold text-slate-900">{race.race_type}</div>
                            </div>
                            <div className="rounded-2xl bg-slate-50 p-3">
                                <div className="text-xs uppercase tracking-[0.22em] text-slate-400">Parcours</div>
                                <div className="mt-1 text-sm font-semibold text-slate-900">
                                    {parcoursIcon[race.terrain?.toLowerCase()] ?? ''} {race.terrain}
                                </div>
                            </div>
                            {race.win_probability != null ? (
                                <div className="rounded-2xl bg-indigo-50 p-3">
                                    <div className="text-xs uppercase tracking-[0.22em] text-indigo-500">Winkans</div>
                                    <div className="mt-1 text-sm font-semibold text-indigo-900">
                                        {race.win_probability}%
                                    </div>
                                </div>
                            ) : race.rider_count != null ? (
                                <div className="rounded-2xl bg-slate-50 p-3">
                                    <div className="text-xs uppercase tracking-[0.22em] text-slate-400">Renners</div>
                                    <div className="mt-1 text-sm font-semibold text-slate-900">{race.rider_count}</div>
                                </div>
                            ) : (
                                <div className="rounded-2xl bg-slate-50 p-3">
                                    <div className="text-xs uppercase tracking-[0.22em] text-slate-400">Renners</div>
                                    <div className="mt-1 text-sm font-semibold text-slate-400">–</div>
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
