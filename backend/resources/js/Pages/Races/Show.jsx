import { Head, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import PredictionEvaluationPanel from '../../Components/PredictionEvaluationPanel';
import PredictionTable from '../../Components/PredictionTable';
import AppLayout from '../../Layouts/AppLayout';

export default function RacesShow({
    race,
    evaluation = null,
    signals = [],
    contenders = [],
    predictions = [],
    predictionGroups = [],
    scenarios = [],
    has_results = false,
}) {
    const { post, processing } = useForm({});
    const [rerunStatus, setRerunStatus] = useState('idle');
    const [rerunProgress, setRerunProgress] = useState(0);
    const [rerunChangeSummary, setRerunChangeSummary] = useState(null);
    const [showRerunFeedback, setShowRerunFeedback] = useState(false);
    const previousRerunStatus = useRef('idle');
    const hasPredictions = predictions.length > 0;
    const extraGroups = predictionGroups.filter((group) => !group.is_primary);

    const rerunModel = () => {
        setRerunStatus('idle');
        setRerunProgress(0);
        setRerunChangeSummary(null);
        setShowRerunFeedback(true);
        post(`/races/${race.slug}/rerun-model`, {
            preserveScroll: true,
            onSuccess: () => {
                setRerunStatus('running');
                setRerunProgress(5);
            },
        });
    };

    useEffect(() => {
        let cancelled = false;

        const pollStatus = async () => {
            try {
                const response = await fetch(`/races/${race.slug}/rerun-model/status`, {
                    headers: { Accept: 'application/json' },
                });

                if (!response.ok || cancelled) {
                    return;
                }

                const data = await response.json();
                const status = data?.status ?? 'idle';
                setRerunStatus(status);
                setRerunProgress(Number(data?.progress_percent ?? 0));
                setRerunChangeSummary(data?.change_summary ?? null);

                if (status === 'running') {
                    // Ook na een manuele refresh moet lopende run zichtbaar blijven.
                    setShowRerunFeedback(true);
                }
            } catch {
                // Pollingfout negeren; volgende interval probeert opnieuw.
            }
        };

        // Altijd 1x status ophalen bij page load (ook na refresh).
        pollStatus();

        // Alleen continu pollen als een run bezig is.
        if (rerunStatus !== 'running') {
            return () => {
                cancelled = true;
            };
        }

        const interval = window.setInterval(pollStatus, 5000);

        return () => {
            cancelled = true;
            window.clearInterval(interval);
        };
    }, [race.slug, rerunStatus]);

    useEffect(() => {
        const wasRunning = previousRerunStatus.current === 'running';
        previousRerunStatus.current = rerunStatus;

        if ((rerunStatus === 'completed' || rerunStatus === 'failed') && !wasRunning) {
            // Oude status uit cache (niet door deze sessie gestart): feedback verbergen.
            setShowRerunFeedback(false);
            return;
        }

        if ((rerunStatus === 'completed' || rerunStatus === 'failed') && wasRunning) {
            setShowRerunFeedback(true);
        }
    }, [rerunStatus]);

    return (
        <AppLayout>
            <Head title={race.name} />

            <div className="space-y-8">
                <section className="grid gap-6 lg:grid-cols-[1fr_0.9fr]">
                    <div className="vp-panel p-6 sm:p-8">
                        <div className="flex flex-wrap items-center gap-3">
                            <span className="rounded-full bg-teal-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.22em] text-teal-700">
                                {race.category}
                            </span>
                            <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">
                                {race.date}
                            </span>
                            {race.is_live && (
                                <span className="rounded-full bg-red-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.22em] text-red-600">
                                    Live
                                </span>
                            )}
                            {race.is_finished && (
                                <span className="rounded-full bg-green-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.22em] text-green-700">
                                    Afgelopen
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

                        <h1 className="mt-5 font-display text-4xl font-semibold tracking-tight text-slate-950 sm:text-5xl">
                            {race.name}
                        </h1>
                        <p className="mt-4 max-w-2xl text-base leading-7 text-slate-600">{race.summary}</p>
                        {(race.startlist_synced_at || race.prediction_updated_at) && (
                            <div className="mt-4 flex flex-wrap gap-3 text-xs text-slate-400">
                                {race.startlist_synced_at && <span>Startlijst ververst: {race.startlist_synced_at}</span>}
                                {race.prediction_updated_at && <span>Voorspellingen vernieuwd: {race.prediction_updated_at}</span>}
                            </div>
                        )}
                        <div className="mt-4 flex flex-wrap items-center gap-3">
                            <button
                                type="button"
                                onClick={rerunModel}
                                disabled={processing || rerunStatus === 'running'}
                                className="vp-button-primary bg-amber-500 text-slate-950 hover:bg-amber-400 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                {processing ? 'Model wordt gestart...' : 'Run Model Opnieuw'}
                            </button>
                            {(showRerunFeedback || rerunStatus === 'running') && (
                                <div className="w-full max-w-lg space-y-1">
                                    <div className="flex items-center justify-between text-xs">
                                        <span
                                            className={`font-medium ${
                                                rerunStatus === 'completed'
                                                    ? 'text-emerald-700'
                                                    : rerunStatus === 'failed'
                                                      ? 'text-rose-700'
                                                      : 'text-amber-700'
                                            }`}
                                        >
                                            {rerunStatus === 'completed'
                                                ? 'Herberekening klaar. Wijzigingen hieronder zichtbaar.'
                                                : rerunStatus === 'failed'
                                                  ? 'Herberekening duurde te lang. Probeer opnieuw.'
                                                  : 'Model wordt opnieuw berekend...'}
                                        </span>
                                        <span className="font-semibold text-slate-600">{rerunProgress}%</span>
                                    </div>
                                    <div className="h-2 w-full overflow-hidden rounded-full bg-slate-200">
                                        <div
                                            className={`h-full rounded-full transition-all duration-500 ${
                                                rerunStatus === 'completed'
                                                    ? 'bg-emerald-500'
                                                    : rerunStatus === 'failed'
                                                      ? 'bg-rose-500'
                                                      : 'bg-amber-500'
                                            }`}
                                            style={{ width: `${Math.max(0, Math.min(100, rerunProgress))}%` }}
                                        />
                                    </div>
                                    {rerunStatus === 'completed' && rerunChangeSummary && (
                                        <div className="rounded-xl border border-emerald-100 bg-emerald-50/80 p-3 text-xs text-emerald-900">
                                            <div className="font-semibold">
                                                Top-10 overlap: {rerunChangeSummary.top10_overlap}/10 · Exacte posities: {rerunChangeSummary.exact_positions}/10
                                            </div>
                                            <div className="mt-1">
                                                Nieuwe namen: {rerunChangeSummary.new_entries} · Weggevallen: {rerunChangeSummary.dropped_entries} · Winkans shifts: {rerunChangeSummary.win_probability_shifts}
                                            </div>
                                            {Array.isArray(rerunChangeSummary.movers) && rerunChangeSummary.movers.length > 0 && (
                                                <div className="mt-1">
                                                    Grootste verschuivingen:{' '}
                                                    {rerunChangeSummary.movers
                                                        .map(
                                                            (item) =>
                                                                `${item.rider} ${item.delta > 0 ? `+${item.delta}` : item.delta}`
                                                        )
                                                        .join(', ')}
                                                </div>
                                            )}
                                        </div>
                                    )}
                                </div>
                            )}
                        </div>

                        <div className="mt-8 grid gap-4 sm:grid-cols-3">
                            <div className="rounded-[24px] bg-slate-50 p-4">
                                <div className="text-xs uppercase tracking-[0.22em] text-slate-400">Terrein</div>
                                <div className="mt-2 text-2xl font-semibold text-slate-950">{race.terrain}</div>
                            </div>
                            <div className="rounded-[24px] bg-slate-50 p-4">
                                <div className="text-xs uppercase tracking-[0.22em] text-slate-400">Status</div>
                                <div className="mt-2 text-lg font-semibold text-slate-950">
                                    {race.is_live ? 'LIVE' : race.is_finished ? 'Afgelopen' : race.race_type}
                                </div>
                            </div>
                            <div className="rounded-[24px] bg-amber-50 p-4">
                                <div className="text-xs uppercase tracking-[0.22em] text-amber-700">Topfavoriet</div>
                                <div className="mt-2 text-xl font-semibold text-amber-950">{race.topPick}</div>
                            </div>
                        </div>
                    </div>

                    <div className="vp-panel-dark p-6">
                        <div className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">
                            {has_results ? 'Primair podium' : race.primaryPredictionTitle}
                        </div>
                        {contenders.length > 0 ? (
                            <div className="mt-4 space-y-3">
                                {contenders.map((contender, index) => (
                                    <div key={index} className="flex items-center justify-between gap-4 rounded-2xl bg-white/5 px-4 py-3">
                                        <div>
                                            <div className="text-xs uppercase tracking-[0.2em] text-slate-400">{contender.role}</div>
                                            <div className="mt-1 font-semibold text-white">{contender.name}</div>
                                            <div className="text-sm text-slate-400">{contender.note}</div>
                                        </div>
                                        {contender.confidence && contender.confidence !== '–' && (
                                            <div className="shrink-0 text-right">
                                                <div className="text-xs uppercase tracking-[0.2em] text-slate-500">Top-10</div>
                                                <div className="text-lg font-semibold text-white">{contender.confidence}</div>
                                            </div>
                                        )}
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <p className="mt-4 text-sm text-slate-400">Geen data beschikbaar.</p>
                        )}
                        <p className="mt-4 text-sm leading-7 text-slate-300">{race.outlook}</p>
                    </div>
                </section>

                <section className="grid gap-4 lg:grid-cols-3">
                    {signals.map((signal) => (
                        <article key={signal.label} className="vp-panel p-5">
                            <div className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">{signal.label}</div>
                            <div className="mt-3 font-display text-3xl font-semibold tracking-tight text-slate-950">{signal.value}</div>
                            <p className="mt-3 text-sm leading-6 text-slate-600">{signal.text}</p>
                        </article>
                    ))}
                </section>

                {hasPredictions && (
                    <section className="vp-panel p-6">
                        <div className="mb-6 flex items-center justify-between gap-4">
                            <div>
                                <div className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">
                                    {race.primaryPredictionTitle}
                                </div>
                                <h2 className="mt-2 font-display text-2xl font-semibold tracking-tight text-slate-950">
                                    Top 10 favorieten
                                </h2>
                            </div>
                            <div className="rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700">
                                Handmatig opnieuw runnen per koers mogelijk
                            </div>
                        </div>

                        <PredictionTable
                            predictions={predictions}
                            showActual={has_results}
                            contextLink={{ race: race?.slug, type: race?.primary_prediction_type ?? 'result', stage: race?.primary_stage_number ?? 0 }}
                        />
                    </section>
                )}

                {evaluation && (
                    <PredictionEvaluationPanel evaluation={evaluation} />
                )}

                {extraGroups.length > 0 && (
                    <section className="space-y-4">
                        <h2 className="font-display text-xl font-semibold text-slate-950">Ritten en klassementen</h2>
                        <div className="grid gap-4 xl:grid-cols-2">
                            {extraGroups.map((group) => (
                                <article key={group.key} className="vp-panel p-5">
                                    <div className="mb-4 flex items-center justify-between gap-3">
                                        <div>
                                            <div className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Voorspellingscontext</div>
                                            <h3 className="mt-2 font-display text-xl font-semibold tracking-tight text-slate-950">{group.title}</h3>
                                            {group.subtitle && (
                                                <div className="mt-2 text-sm font-semibold text-slate-600">{group.subtitle}</div>
                                            )}
                                        </div>
                                        <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">
                                            Top 10
                                        </span>
                                    </div>

                                    <PredictionTable
                                        predictions={group.predictions}
                                        showActual={group.predictions?.some((row) => row.actual_position != null)}
                                        contextLink={{ race: race?.slug, type: group.key?.split(':')?.[0] ?? 'result', stage: Number(group.key?.split(':')?.[1] ?? 0) }}
                                    />
                                </article>
                            ))}
                        </div>
                    </section>
                )}

                {scenarios.length > 0 && (
                    <section className="space-y-4">
                        <h2 className="font-display text-xl font-semibold text-slate-950">Koersscenario&apos;s</h2>
                        <div className="grid gap-4 lg:grid-cols-3">
                            {scenarios.map((scenario, index) => (
                                <article key={index} className="vp-panel p-5">
                                    <div className="font-semibold text-slate-900">{scenario.title}</div>
                                    <p className="mt-2 text-sm leading-6 text-slate-600">{scenario.text}</p>
                                </article>
                            ))}
                        </div>
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
