import { Head, Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import PredictionEvaluationPanel from '../../Components/PredictionEvaluationPanel';
import PredictionTable from '../../Components/PredictionTable';
import SectionHeading from '../../Components/SectionHeading';
import AppLayout from '../../Layouts/AppLayout';

export default function PredictionsIndex({
    race = null,
    evaluation = null,
    predictions = [],
    predictionGroups = [],
    scenarios = [],
    availableRaces = [],
    otherRaces = [],
}) {
    const [query, setQuery] = useState('');
    const [isSwitchingRace, setIsSwitchingRace] = useState(false);

    const hasPredictions = predictions.length > 0;
    const extraGroups = predictionGroups.filter((group) => !group.is_primary);
    const selectedRaceSlug = availableRaces.find((option) => option.is_selected)?.slug ?? '';

    const normalizedQuery = query.trim().toLowerCase();
    const filteredPredictions = useMemo(() => {
        if (!normalizedQuery) return predictions;
        return predictions.filter((row) => (row.rider ?? '').toLowerCase().includes(normalizedQuery));
    }, [normalizedQuery, predictions]);

    const filteredExtraGroups = useMemo(() => {
        if (!normalizedQuery) return extraGroups;
        return extraGroups
            .map((group) => ({
                ...group,
                predictions: (group.predictions ?? []).filter((row) => (row.rider ?? '').toLowerCase().includes(normalizedQuery)),
            }))
            .filter((group) => (group.predictions ?? []).length > 0);
    }, [normalizedQuery, extraGroups]);

    function handleRaceChange(event) {
        const nextRace = event.target.value;

        router.get(
            '/predictions',
            nextRace ? { race: nextRace } : {},
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setIsSwitchingRace(true),
                onFinish: () => setIsSwitchingRace(false),
            },
        );
    }

    return (
        <AppLayout>
            <Head title="Voorspellingen" />

            <div className="space-y-10">
                <SectionHeading
                    eyebrow="Voorspellingsdesk"
                    title="Uitlegbare voorspellingen met duidelijke koerslogica."
                    description="Dit overzicht toont de meest relevante koers met modelvoorspellingen, winkansen en verklarende scenario&apos;s."
                />

                {race && (
                    <div className="flex flex-wrap items-center justify-between gap-4">
                        <div className="flex flex-wrap items-center gap-3">
                            <Link
                                href={`/races/${race.slug}`}
                                className="font-display text-2xl font-semibold text-slate-950 hover:text-indigo-700"
                            >
                                {race.name}
                            </Link>
                            <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">
                                {race.date}
                            </span>
                            <span className="rounded-full bg-teal-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.22em] text-teal-700">
                                {race.terrain}
                            </span>
                            {race.is_live && (
                                <span className="rounded-full bg-red-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.22em] text-red-600">
                                    Live
                                </span>
                            )}
                            {race.is_finished && race.has_results && (
                                <span className="rounded-full bg-green-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.22em] text-green-700">
                                    Uitslag beschikbaar
                                </span>
                            )}
                            {race.startlist_count != null && (
                                <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">
                                    {race.startlist_count} starters
                                </span>
                            )}
                            {race.prediction_model_version && (
                                <span className="rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.22em] text-indigo-700">
                                    {race.prediction_model_version}
                                </span>
                            )}
                        </div>
                        <div className="flex flex-wrap items-center gap-3">
                            {availableRaces.length > 1 && (
                                <div className="relative flex items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-2 text-sm text-slate-600">
                                    <span className="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400 whitespace-nowrap">
                                        Kies koers
                                    </span>
                                    <div className="relative">
                                        <select
                                            value={selectedRaceSlug}
                                            onChange={handleRaceChange}
                                            disabled={isSwitchingRace}
                                            className="appearance-none bg-transparent pl-0 pr-5 text-sm font-medium text-slate-900 outline-none cursor-pointer disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            {availableRaces.map((option) => (
                                                <option key={option.slug} value={option.slug}>
                                                    {option.name} · {option.date}
                                                </option>
                                            ))}
                                        </select>
                                        <div className="pointer-events-none absolute inset-y-0 right-0 flex items-center">
                                            <svg className="h-3.5 w-3.5 text-slate-400" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                <path d="M4 6l4 4 4-4" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round"/>
                                            </svg>
                                        </div>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>
                )}

                {race && (race.startlist_synced_at || race.prediction_updated_at) && (
                    <div className="flex flex-wrap gap-3 text-xs text-slate-400">
                        {race.startlist_synced_at && <span>Startlijst ververst: {race.startlist_synced_at}</span>}
                        {race.prediction_updated_at && <span>Voorspellingen vernieuwd: {race.prediction_updated_at}</span>}
                        {isSwitchingRace && <span className="font-semibold text-indigo-600">Bezig met laden…</span>}
                    </div>
                )}

                {hasPredictions && (
                    <div className="vp-panel p-4">
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div className="flex items-center gap-2">
                                <div className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">
                                    Snelle filter
                                </div>
                                {normalizedQuery && (
                                    <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-600">
                                        {filteredPredictions.length}/10
                                    </span>
                                )}
                            </div>
                            <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center">
                                <input
                                    value={query}
                                    onChange={(event) => setQuery(event.target.value)}
                                    placeholder="Zoek renner (bv. Van Aert)…"
                                    className="w-full rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm text-slate-900 outline-none placeholder:text-slate-400 focus:border-indigo-300 focus:ring-2 focus:ring-indigo-100 sm:w-72"
                                />
                                <div className="flex flex-wrap gap-2">
                                    <a href="#top10" className="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600 hover:bg-slate-200">
                                        Top 10
                                    </a>
                                    {extraGroups.length > 0 && (
                                        <a href="#extra" className="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600 hover:bg-slate-200">
                                            Ritten/klassementen
                                        </a>
                                    )}
                                    {scenarios.length > 0 && (
                                        <a href="#scenarios" className="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600 hover:bg-slate-200">
                                            Scenario&apos;s
                                        </a>
                                    )}
                                </div>
                            </div>
                        </div>
                    </div>
                )}

                {!hasPredictions && (
                    <div className="vp-panel p-8 text-center">
                        <p className="text-slate-500">
                            Nog geen voorspellingen beschikbaar. Voer eerst{' '}
                            <code className="rounded bg-slate-100 px-1.5 py-0.5 text-xs font-mono">php artisan predict:race --all</code>{' '}
                            uit.
                        </p>
                    </div>
                )}

                <div className="grid gap-8 lg:grid-cols-[1fr_280px]">
                    <div className="space-y-8">
                        {hasPredictions && (
                            <section id="top10" className="vp-panel scroll-mt-24 p-6">
                                <div className="mb-6 flex items-center justify-between gap-4">
                                    <div>
                                        <div className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">
                                            {race?.has_results ? 'Modelvoorspelling tegenover uitslag' : race?.primary_prediction_title}
                                        </div>
                                        <h3 className="mt-2 font-display text-2xl font-semibold tracking-tight text-slate-950">
                                            Top 10 favorieten
                                        </h3>
                                    </div>
                                    <span className="rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700">
                                        Model live doorgerekend bij elke update
                                    </span>
                                </div>

                                <PredictionTable
                                    predictions={filteredPredictions}
                                    showActual={race?.has_results}
                                    contextLink={{ race: race?.slug, type: race?.primary_prediction_type ?? 'result', stage: race?.primary_stage_number ?? 0 }}
                                />

                                {race?.has_results && (
                                    <div className="mt-6 flex flex-wrap gap-4 border-t border-slate-100 pt-4 text-xs text-slate-400">
                                        <span><span className="font-semibold text-green-600">✓ n</span> = correcte positie</span>
                                        <span><span className="font-semibold text-teal-600">↑ n</span> = beter dan voorspeld</span>
                                        <span><span className="font-semibold text-red-400">↓ n</span> = slechter dan voorspeld</span>
                                    </div>
                                )}
                            </section>
                        )}

                        {evaluation && (
                            <PredictionEvaluationPanel evaluation={evaluation} />
                        )}

                        {extraGroups.length > 0 && (
                            <section id="extra" className="space-y-4 scroll-mt-24">
                                <h3 className="font-display text-xl font-semibold text-slate-950">Ritten en klassementen</h3>
                                <div className="grid gap-4 xl:grid-cols-2">
                                    {filteredExtraGroups.map((group) => (
                                        <article key={group.key} className="vp-panel p-6">
                                            <div className="mb-4 flex items-center justify-between gap-3">
                                                <div>
                                                    <div className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Voorspellingscontext</div>
                                                    <h4 className="mt-2 font-display text-xl font-semibold tracking-tight text-slate-950">{group.title}</h4>
                                                </div>
                                                <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">
                                                    Top 10
                                                </span>
                                            </div>

                                            <PredictionTable
                                                predictions={group.predictions}
                                                contextLink={{ race: race?.slug, type: group.key?.split(':')?.[0] ?? 'result', stage: Number(group.key?.split(':')?.[1] ?? 0) }}
                                            />
                                        </article>
                                    ))}
                                </div>
                            </section>
                        )}

                        {scenarios.length > 0 && (
                            <section id="scenarios" className="space-y-4 scroll-mt-24">
                                <h3 className="font-display text-xl font-semibold text-slate-950">Koersscenario&apos;s</h3>
                                <div className="grid gap-4 lg:grid-cols-3">
                                    {scenarios.map((scenario, index) => (
                                        <article key={index} className="vp-panel p-6">
                                            <div className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Scenario</div>
                                            <h4 className="mt-4 font-display text-xl font-semibold tracking-tight text-slate-950">{scenario.title}</h4>
                                            <p className="mt-3 text-sm leading-7 text-slate-600">{scenario.text}</p>
                                            {scenario.effect && (
                                                <div className="mt-6 rounded-2xl bg-slate-50 p-4">
                                                    <div className="text-xs uppercase tracking-[0.22em] text-slate-400">Effect</div>
                                                    <div className="mt-2 text-sm font-semibold text-slate-900">{scenario.effect}</div>
                                                </div>
                                            )}
                                        </article>
                                    ))}
                                </div>
                            </section>
                        )}
                    </div>

                    {otherRaces.length > 0 && (
                        <aside className="space-y-3 lg:sticky lg:top-24 lg:self-start">
                            <div className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">
                                Andere koersen
                            </div>
                            {otherRaces.map((otherRace) => (
                                <Link
                                    key={otherRace.slug}
                                    href={`/predictions?race=${otherRace.slug}`}
                                    className="flex items-center justify-between gap-3 rounded-2xl border border-slate-100 bg-white p-4 transition-colors hover:border-slate-200 hover:bg-slate-50"
                                >
                                    <div>
                                        <div className="text-sm font-semibold text-slate-900">{otherRace.name}</div>
                                        <div className="mt-0.5 text-xs text-slate-400">{otherRace.date} · {otherRace.terrain}</div>
                                    </div>
                                    {otherRace.upcoming ? (
                                        <span className="shrink-0 rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-semibold text-indigo-600">
                                            Binnenkort
                                        </span>
                                    ) : (
                                        <span className="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-500">
                                            Afgelopen
                                        </span>
                                    )}
                                </Link>
                            ))}
                        </aside>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
