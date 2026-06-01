import { Head, Link } from '@inertiajs/react';
import { Activity, Calendar, Database, Mountain, Route, Target, Trophy, Users, Waves } from 'lucide-react';
import EmptyState from '../Components/EmptyState';
import PredictionTable from '../Components/PredictionTable';
import AppLayout from '../Layouts/AppLayout';

function ModelStatus({ liveBoard, evaluationSummary }) {
    const recent = evaluationSummary?.recent ?? null;
    const latest = evaluationSummary?.latest ?? null;
    const avgTop10Hits = typeof recent?.avg_top10_hits === 'number' ? recent.avg_top10_hits : null;
    const top10HitRatePct = avgTop10Hits != null ? Math.max(0, Math.min(100, Math.round((avgTop10Hits / 10) * 100))) : null;

    return (
        <div className="vp-panel space-y-4 p-5">
            <div className="flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">
                <span className="vp-icon-box"><Database size={14} /></span>
                Model status
            </div>
            <div className="mt-3 flex items-center justify-between gap-3">
                <div>
                    <div className="text-sm text-slate-300">Model version</div>
                    <div className="mt-1 font-semibold text-slate-100">{liveBoard?.model_version ?? 'Onbekend'}</div>
                </div>
                <span className="vp-accent-pill">{liveBoard ? 'Actief' : 'Nog geen data'}</span>
            </div>

            <div className="rounded-md border border-slate-700/80 bg-slate-900/55 p-3">
                <div className="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-400">
                    <span className="vp-icon-box"><Target size={13} /></span>
                    Recente performance
                </div>
                {recent && avgTop10Hits != null ? (
                    <div className="mt-2 space-y-2">
                        <div className="flex items-end justify-between gap-3">
                            <div>
                                <div className="text-xl font-semibold text-slate-100">{avgTop10Hits.toFixed(1)} / 10</div>
                                <div className="text-xs text-slate-400">Gemiddelde top-10 hits ({recent.count} evaluaties)</div>
                            </div>
                            <div className="text-right">
                                <div className="text-lg font-semibold text-emerald-300">{top10HitRatePct}%</div>
                                <div className="text-xs text-slate-400">Top-10 hitrate</div>
                            </div>
                        </div>
                        {latest?.race?.name ? (
                            <div className="text-xs text-slate-400">
                                Laatste evaluatie:{' '}
                                <span className="text-slate-300">
                                    {latest.race.name}{latest.context_label ? ` • ${latest.context_label}` : ''}
                                </span>
                            </div>
                        ) : null}
                    </div>
                ) : (
                    <div className="mt-2 text-xs text-slate-400">Nog geen recente evaluaties beschikbaar.</div>
                )}
            </div>
        </div>
    );
}

function cleanLabel(value, fallback) {
    if (!value || typeof value !== 'string') return fallback;
    // Strip replacement/garbled prefix chars like "� mountain".
    const cleaned = value.replace(/^[^\p{L}\p{N}]+/u, '').trim();
    return cleaned || fallback;
}

function terrainIcon(terrainRaw) {
    const terrain = cleanLabel(terrainRaw, '').toLowerCase();
    if (terrain.includes('mountain') || terrain.includes('climb')) return Mountain;
    if (terrain.includes('hilly')) return Activity;
    if (terrain.includes('flat') || terrain.includes('sprint')) return Waves;
    return Route;
}

export default function Dashboard({ liveBoard = null, evaluationSummary = null, featuredRaces = [], featuredRiders = [] }) {
    const topEntries = Array.isArray(liveBoard?.entries) ? liveBoard.entries.slice(0, 3) : [];
    const terrainLabel = cleanLabel(liveBoard?.terrain, 'Parcours onbekend');
    const categoryLabel = cleanLabel(liveBoard?.category, 'Categorie onbekend');
    const TerrainIcon = terrainIcon(liveBoard?.terrain);

    return (
        <AppLayout>
            <Head title="Dashboard" />

            <div className="space-y-8">
                <section className="grid gap-5 lg:grid-cols-[1.25fr_0.75fr]">
                    <div className="vp-panel p-6 sm:p-8">
                        <div className="vp-pill">Next race prediction</div>
                        {liveBoard ? (
                            <>
                                <h1 className="mt-5 font-display text-4xl font-semibold tracking-tight sm:text-5xl">{liveBoard.name}</h1>
                                <div className="mt-3 flex flex-wrap items-center gap-3 text-sm text-slate-300">
                                    <span className="inline-flex items-center gap-1"><Calendar size={14} /> {liveBoard.date ?? 'Datum onbekend'}</span>
                                    <span aria-hidden="true">|</span>
                                    <span className="inline-flex items-center gap-1"><TerrainIcon size={14} /> {terrainLabel}</span>
                                    <span aria-hidden="true">|</span>
                                    <span className="inline-flex items-center gap-1"><Users size={14} /> {categoryLabel}</span>
                                </div>
                                <p className="mt-4 max-w-3xl text-sm leading-7 text-slate-300">
                                    {liveBoard.leadScenarioText ?? 'Nog geen modelcontext beschikbaar voor deze koers.'}
                                </p>
                                <div className="mt-6 flex flex-wrap gap-3">
                                    <Link href="/predictions" className="vp-button-primary">Open predictions</Link>
                                    {liveBoard.slug ? <Link href={`/races/${liveBoard.slug}`} className="vp-button-secondary">Bekijk koers</Link> : null}
                                </div>
                            </>
                        ) : (
                            <EmptyState title="Nog geen actieve race" message="Er is nog geen actuele race met voorspellingen beschikbaar." />
                        )}
                    </div>

                    <ModelStatus liveBoard={liveBoard} evaluationSummary={evaluationSummary} />
                </section>

                {topEntries.length > 0 ? (
                    <section className="vp-panel p-6">
                        <div className="mb-5 flex items-center justify-between">
                            <h2 className="inline-flex items-center gap-2 font-display text-2xl font-semibold"><Trophy size={18} /> Topfavorieten</h2>
                            <span className="text-xs uppercase tracking-[0.2em] text-slate-400">Probabilistisch</span>
                        </div>
                        <PredictionTable entries={topEntries} />
                    </section>
                ) : null}

                <section className="space-y-4">
                    <div className="flex items-center justify-between">
                        <h2 className="inline-flex items-center gap-2 font-display text-2xl font-semibold"><Calendar size={18} /> Komende koersen</h2>
                        <Link href="/races" className="text-sm font-semibold text-slate-300 hover:text-white">Alle koersen</Link>
                    </div>
                    {featuredRaces.length > 0 ? (
                        <div className="grid gap-4 lg:grid-cols-3">
                            {featuredRaces.map((race) => (
                                <Link key={race.slug} href={`/races/${race.slug}`} className="vp-panel p-5 transition hover:-translate-y-0.5">
                                    <div className="text-xs uppercase tracking-[0.2em] text-slate-400">{race.date ?? 'Datum onbekend'}</div>
                                    <h3 className="mt-3 font-display text-xl font-semibold">{race.name}</h3>
                                    <div className="mt-2 text-sm text-slate-300">{race.terrain ?? 'Parcours onbekend'}</div>
                                    <p className="mt-3 text-sm text-slate-300">{race.summary ?? 'Nog geen samenvatting beschikbaar.'}</p>
                                </Link>
                            ))}
                        </div>
                    ) : (
                        <EmptyState title="Nog geen komende koersen" message="Er zijn nog geen komende koersen met beschikbare data." />
                    )}
                </section>

                <section className="space-y-4">
                    <div className="flex items-center justify-between">
                        <h2 className="inline-flex items-center gap-2 font-display text-2xl font-semibold"><Target size={18} /> Renners in focus</h2>
                        <Link href="/riders" className="text-sm font-semibold text-slate-300 hover:text-white">Alle renners</Link>
                    </div>
                    {featuredRiders.length > 0 ? (
                        <div className="grid gap-4 lg:grid-cols-3">
                            {featuredRiders.map((rider) => (
                                <Link key={rider.slug} href={`/riders/${rider.slug}`} className="vp-panel p-5">
                                    <h3 className="font-display text-xl font-semibold">{rider.name}</h3>
                                    <p className="mt-1 text-sm text-slate-300">{rider.team || 'Onbekend team'}</p>
                                    <p className="mt-3 text-sm text-slate-300">{rider.profile ?? 'Modeldata ontbreekt voor deze renner.'}</p>
                                </Link>
                            ))}
                        </div>
                    ) : (
                        <EmptyState title="Geen renners in focus" message="Er zijn nog geen renners met zichtbare modelcontext." />
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
