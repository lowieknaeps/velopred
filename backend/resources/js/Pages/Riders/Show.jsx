import { Head, Link } from '@inertiajs/react';
import { Activity, Calendar, Flag, Gauge, TrendingUp, User } from 'lucide-react';
import EmptyState from '../../Components/EmptyState';
import AppLayout from '../../Layouts/AppLayout';

function PositionBadge({ pos, status }) {
    if (status !== 'finished' || pos == null) return <span className="text-slate-400">DNF</span>;
    return <span className="rounded-md border border-slate-600 bg-slate-900 px-2 py-0.5 text-xs font-semibold text-slate-200">#{pos}</span>;
}

export default function RidersShow({ rider, indicators = [], recentResults = [], upcomingPredictions = [], explainability = null }) {
    return (
        <AppLayout>
            <Head title={rider.name} />

            <div className="space-y-8">
                <section className="grid gap-5 lg:grid-cols-[1.1fr_0.9fr]">
                    <div className="vp-panel p-6 sm:p-8">
                        <div className="mb-4">
                            {rider.photo_url ? (
                                <div className="h-32 w-24 overflow-hidden rounded-lg border border-slate-700 bg-slate-900">
                                    <img src={rider.photo_url} alt={`Profielfoto van ${rider.name}`} className="h-full w-full object-cover object-top" loading="lazy" referrerPolicy="no-referrer" />
                                </div>
                            ) : (
                                <div className="flex h-32 w-24 items-center justify-center rounded-lg border border-slate-700 bg-slate-900 text-xs font-semibold text-slate-400">Geen foto</div>
                            )}
                        </div>
                        <div className="inline-flex items-center gap-2 text-xs uppercase tracking-[0.22em] text-slate-400"><span className="vp-icon-box"><User size={13} /></span>{rider.team || 'Onbekend team'}</div>
                        <h1 className="mt-3 font-display text-4xl font-semibold tracking-tight sm:text-5xl">{rider.name}</h1>
                        <p className="mt-4 text-sm leading-7 text-slate-300">{rider.profile || 'Modeldata ontbreekt voor deze renner.'}</p>

                        <div className="mt-6 grid gap-3 sm:grid-cols-3">
                            <div className="rounded-lg border border-slate-700 bg-slate-900/70 p-4"><div className="text-xs uppercase tracking-[0.2em] text-slate-400">{rider.ratingLabel || 'Ranking'}</div><div className="mt-1 text-2xl font-semibold text-slate-100">{rider.rating ?? 'N/A'}</div></div>
                            <div className="rounded-lg border border-slate-700 bg-slate-900/70 p-4"><div className="text-xs uppercase tracking-[0.2em] text-slate-400">{rider.strengthLabel || 'Signaal'}</div><div className="mt-1 text-sm font-semibold text-slate-100">{rider.strength || 'Geen data'}</div></div>
                            <div className="rounded-lg border border-slate-700 bg-slate-900/70 p-4"><div className="text-xs uppercase tracking-[0.2em] text-slate-400">Leeftijd</div><div className="mt-1 text-sm font-semibold text-slate-100">{rider.age ? `${rider.age} jaar` : 'Onbekend'}</div></div>
                        </div>
                    </div>

                    <div className="vp-panel-dark p-6">
                        <div className="inline-flex items-center gap-2 text-xs uppercase tracking-[0.2em] text-slate-400"><span className="vp-icon-box"><TrendingUp size={13} /></span>Trend</div>
                        <div className="mt-3 font-display text-3xl font-semibold">{rider.trend || 'Geen trend beschikbaar'}</div>
                        <p className="mt-4 text-sm leading-7 text-slate-300">{rider.outlook || 'Nog geen context beschikbaar.'}</p>
                    </div>
                </section>

                {upcomingPredictions.length > 0 ? (
                    <section className="vp-panel p-6">
                        <h2 className="inline-flex items-center gap-2 font-display text-2xl font-semibold"><Calendar size={18} /> Komende predictions</h2>
                        <div className="mt-4 grid gap-4 lg:grid-cols-3">
                            {upcomingPredictions.map((prediction) => (
                                <Link key={`${prediction.slug}-${prediction.position}`} href={`/races/${prediction.slug}`} className="rounded-lg border border-slate-700 bg-slate-900/70 p-4">
                                    <div className="text-xs uppercase tracking-[0.2em] text-slate-400">{prediction.date}</div>
                                    <div className="mt-2 font-semibold text-slate-100">{prediction.race}</div>
                                    <div className="mt-3 text-xs text-slate-400">Projectie #{prediction.position}</div>
                                    <div className="mt-2 text-sm text-slate-200">Win {prediction.win_probability}% · Top-10 {prediction.top10_probability}%</div>
                                </Link>
                            ))}
                        </div>
                    </section>
                ) : null}

                {explainability ? (
                    <section className="vp-panel p-6">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <h2 className="inline-flex items-center gap-2 font-display text-2xl font-semibold"><Gauge size={18} /> Prediction context</h2>
                            <span className="vp-accent-pill">Model {explainability.model_version}</span>
                        </div>
                        <div className="mt-4 grid gap-4 lg:grid-cols-5">
                            {explainability.signals.map((signal) => (
                                <article key={signal.label} className="rounded-lg border border-slate-700 bg-slate-900/70 p-4">
                                    <div className="text-xs uppercase tracking-[0.2em] text-slate-400">{signal.label}</div>
                                    <div className="mt-2 text-lg font-semibold text-slate-100">{signal.value}</div>
                                    <p className="mt-2 text-xs leading-6 text-slate-300">{signal.detail}</p>
                                </article>
                            ))}
                        </div>
                    </section>
                ) : (
                    <EmptyState title="Geen modeluitleg" message="Modeldata ontbreekt voor deze renner in de geselecteerde context." />
                )}

                {indicators.length > 0 ? (
                    <section className="grid gap-4 lg:grid-cols-3">
                        {indicators.map((indicator) => (
                            <article key={indicator.label} className="vp-panel p-5">
                                <div className="inline-flex items-center gap-2 text-xs uppercase tracking-[0.22em] text-slate-400"><span className="vp-icon-box"><Activity size={13} /></span>{indicator.label}</div>
                                <div className="mt-2 font-display text-2xl font-semibold">{indicator.value}</div>
                                <p className="mt-2 text-sm text-slate-300">{indicator.text}</p>
                            </article>
                        ))}
                    </section>
                ) : null}

                {recentResults.length > 0 ? (
                    <section className="vp-panel p-6">
                        <h2 className="inline-flex items-center gap-2 font-display text-2xl font-semibold"><Flag size={18} /> Recente resultaten</h2>
                        <div className="mt-4 overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b border-slate-700">
                                        <th className="pb-3 text-left text-xs uppercase tracking-[0.2em] text-slate-400">Koers</th>
                                        <th className="pb-3 text-left text-xs uppercase tracking-[0.2em] text-slate-400">Datum</th>
                                        <th className="pb-3 text-right text-xs uppercase tracking-[0.2em] text-slate-400">Positie</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-800">
                                    {recentResults.map((r, i) => (
                                        <tr key={i}>
                                            <td className="py-3"><Link href={`/races/${r.slug}`} className="text-slate-100 hover:text-white">{r.race}</Link></td>
                                            <td className="py-3 text-slate-400">{r.date}</td>
                                            <td className="py-3 text-right"><PositionBadge pos={r.position} status={r.status} /></td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                ) : null}
            </div>
        </AppLayout>
    );
}
