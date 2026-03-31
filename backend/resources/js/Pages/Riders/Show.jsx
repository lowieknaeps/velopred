import { Head, Link } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';

function PositionBadge({ pos, status }) {
    if (status !== 'finished' || pos == null) {
        return <span className="text-slate-400">DNF</span>;
    }
    const base = 'inline-flex h-7 w-7 items-center justify-center rounded-full text-xs font-bold';
    if (pos === 1)  return <span className={`${base} bg-amber-100 text-amber-800`}>1</span>;
    if (pos <= 3)   return <span className={`${base} bg-slate-100 text-slate-700`}>{pos}</span>;
    if (pos <= 10)  return <span className={`${base} bg-teal-50 text-teal-700`}>{pos}</span>;
    return              <span className="text-sm text-slate-500">{pos}</span>;
}

export default function RidersShow({ rider, indicators = [], recentResults = [], upcomingPredictions = [] }) {
    return (
        <AppLayout>
            <Head title={rider.name} />

            <div className="space-y-8">

                {/* Rider header */}
                <section className="grid gap-6 lg:grid-cols-[1fr_0.8fr]">
                    <div className="vp-panel p-6 sm:p-8">
                        <div className="mb-4">
                            {rider.photo_url ? (
                                <div className="h-44 w-32 overflow-hidden rounded-2xl border border-slate-200 bg-slate-50 shadow-sm">
                                    <img
                                        src={rider.photo_url}
                                        alt={`Profielfoto van ${rider.name}`}
                                        className="h-full w-full object-contain object-top"
                                        loading="lazy"
                                        referrerPolicy="no-referrer"
                                    />
                                </div>
                            ) : (
                                <div className="flex h-44 w-32 items-center justify-center rounded-2xl border border-slate-200 bg-slate-50 text-sm font-semibold text-slate-500">
                                    Geen foto
                                </div>
                            )}
                        </div>
                        <div className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">{rider.team}</div>
                        <h1 className="mt-4 font-display text-4xl font-semibold tracking-tight text-slate-950 sm:text-5xl">
                            {rider.name}
                        </h1>
                        <p className="mt-4 max-w-2xl text-base leading-7 text-slate-600">{rider.profile}</p>

                        <div className="mt-8 grid gap-4 sm:grid-cols-3">
                            <div className="rounded-[24px] bg-slate-50 p-4">
                                <div className="text-xs uppercase tracking-[0.22em] text-slate-400">{rider.ratingLabel ?? 'Ranking'}</div>
                                <div className="mt-2 text-3xl font-semibold text-slate-950">
                                    {rider.rating ?? '–'}
                                </div>
                            </div>
                            <div className="rounded-[24px] bg-teal-50 p-4">
                                <div className="text-xs uppercase tracking-[0.22em] text-teal-700">{rider.strengthLabel ?? 'Gem. positie'}</div>
                                <div className="mt-2 text-lg font-semibold text-teal-950">{rider.strength}</div>
                            </div>
                            <div className="rounded-[24px] bg-amber-50 p-4">
                                <div className="text-xs uppercase tracking-[0.22em] text-amber-700">Nationaliteit</div>
                                <div className="mt-2 text-lg font-semibold text-amber-950">
                                    {rider.nationality ?? '–'}
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className="vp-panel-dark p-6">
                        <div className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">{rider.trendLabel ?? 'Huidige trend'}</div>
                        <div className="mt-4 font-display text-3xl font-semibold tracking-tight text-white">{rider.trend}</div>
                        <p className="mt-4 text-sm leading-7 text-slate-300">{rider.outlook}</p>

                        {/* Basisinfo */}
                        <div className="mt-6 space-y-2 border-t border-white/10 pt-5">
                            {rider.date_of_birth && (
                                <div className="flex justify-between text-sm">
                                    <span className="text-slate-400">Geboortedatum</span>
                                    <span className="font-medium text-white">{rider.date_of_birth}</span>
                                </div>
                            )}
                            {rider.age && (
                                <div className="flex justify-between text-sm">
                                    <span className="text-slate-400">Leeftijd</span>
                                    <span className="font-medium text-white">{rider.age} jaar</span>
                                </div>
                            )}
                            {rider.team && (
                                <div className="flex justify-between text-sm">
                                    <span className="text-slate-400">Ploeg</span>
                                    <span className="font-medium text-white">{rider.team}</span>
                                </div>
                            )}
                        </div>
                    </div>
                </section>

                {upcomingPredictions.length > 0 && (
                    <section className="vp-panel p-6">
                        <div className="mb-6">
                            <div className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Voorspellingsdesk</div>
                            <h2 className="mt-2 font-display text-2xl font-semibold tracking-tight text-slate-950">
                                Komende koersverwachtingen
                            </h2>
                        </div>

                        <div className="grid gap-4 lg:grid-cols-3">
                            {upcomingPredictions.map((prediction) => (
                                <Link
                                    key={`${prediction.slug}-${prediction.position}`}
                                    href={`/races/${prediction.slug}`}
                                    className="rounded-[24px] border border-slate-100 bg-white p-5 transition hover:border-slate-950 hover:bg-slate-50"
                                >
                                    <div className="flex items-start justify-between gap-4">
                                        <div>
                                            <div className="text-xs font-semibold uppercase tracking-[0.22em] text-slate-400">
                                                {prediction.date}
                                            </div>
                                            <div className="mt-2 font-display text-xl font-semibold tracking-tight text-slate-950">
                                                {prediction.race}
                                            </div>
                                            {prediction.context && (
                                                <div className="mt-2 text-xs font-semibold uppercase tracking-[0.22em] text-indigo-600">
                                                    {prediction.context}
                                                </div>
                                            )}
                                        </div>
                                        <div className="rounded-2xl bg-slate-950 px-3 py-2 text-white">
                                            <div className="text-[11px] uppercase tracking-[0.22em] text-slate-300">Projectie</div>
                                            <div className="text-lg font-semibold">#{prediction.position}</div>
                                        </div>
                                    </div>

                                    <div className="mt-5 grid grid-cols-2 gap-3">
                                        <div className="rounded-2xl bg-slate-50 p-3">
                                            <div className="text-xs uppercase tracking-[0.22em] text-slate-400">Winkans</div>
                                            <div className="mt-1 text-sm font-semibold text-slate-900">{prediction.win_probability}%</div>
                                        </div>
                                        <div className="rounded-2xl bg-teal-50 p-3">
                                            <div className="text-xs uppercase tracking-[0.22em] text-teal-700">Top-10 kans</div>
                                            <div className="mt-1 text-sm font-semibold text-teal-950">{prediction.top10_probability}%</div>
                                        </div>
                                    </div>
                                </Link>
                            ))}
                        </div>
                    </section>
                )}

                {/* Indicatoren */}
                <section className="grid gap-4 lg:grid-cols-3">
                    {indicators.map((indicator) => (
                        <article key={indicator.label} className="vp-panel p-5">
                            <div className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">{indicator.label}</div>
                            <div className="mt-3 font-display text-3xl font-semibold tracking-tight text-slate-950">{indicator.value}</div>
                            <p className="mt-3 text-sm leading-6 text-slate-600">{indicator.text}</p>
                        </article>
                    ))}
                </section>

                {/* Recente resultaten tabel */}
                {recentResults.length > 0 && (
                    <section className="vp-panel p-6">
                        <div className="mb-6">
                            <div className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Palmares</div>
                            <h2 className="mt-2 font-display text-2xl font-semibold tracking-tight text-slate-950">
                                Recente resultaten
                            </h2>
                        </div>

                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b border-slate-100">
                                        <th className="pb-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Koers</th>
                                        <th className="hidden pb-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-400 sm:table-cell">Type</th>
                                        <th className="hidden pb-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-400 md:table-cell">Datum</th>
                                        <th className="hidden pb-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-400 lg:table-cell">Parcours</th>
                                        <th className="pb-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Positie</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-50">
                                    {recentResults.map((r, i) => (
                                        <tr key={i} className="transition-colors hover:bg-slate-50">
                                            <td className="py-3">
                                                <Link
                                                    href={`/races/${r.slug}`}
                                                    className="font-medium text-slate-900 hover:text-indigo-600"
                                                >
                                                    {r.race}
                                                </Link>
                                            </td>
                                            <td className="hidden py-3 text-slate-500 sm:table-cell">{r.type}</td>
                                            <td className="hidden py-3 text-slate-500 md:table-cell">{r.date}</td>
                                            <td className="hidden py-3 lg:table-cell">
                                                <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">
                                                    {r.parcours}
                                                </span>
                                            </td>
                                            <td className="py-3 text-right">
                                                <PositionBadge pos={r.position} status={r.status} />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
