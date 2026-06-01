import { useState } from 'react';
import { Info } from 'lucide-react';

export default function PredictionEvaluationPanel({
    evaluation,
    title = 'Model versus uitslag',
    collapsible = false,
    defaultExpanded = true,
}) {
    if (!evaluation) return null;

    const [expanded, setExpanded] = useState(defaultExpanded);
    const actualTop10 = Array.isArray(evaluation.actual_top10) ? evaluation.actual_top10 : [];

    const predictedRows = actualTop10.filter(
        (row) => row?.predicted_position !== null && row?.predicted_position !== undefined
    );
    const top10Hits = evaluation.top10_hits ?? predictedRows.filter((row) => Number(row.predicted_position) <= 10).length;
    const top10HitRate = evaluation.top10_hit_rate ?? (top10Hits / 10);
    const podiumHits = evaluation.podium_hits ?? predictedRows.filter((row) => Number(row.predicted_position) <= 3).length;
    const exactPositionHits = evaluation.exact_position_hits ?? predictedRows.filter((row) => Number(row.predicted_position) === Number(row.actual_position)).length;

    const derivedMae = predictedRows.length > 0
        ? predictedRows.reduce((acc, row) => acc + Math.abs(Number(row.predicted_position) - Number(row.actual_position)), 0) / predictedRows.length
        : null;
    const maeValue = evaluation.mean_absolute_position_error ?? derivedMae;

    const hitRateLabel = top10HitRate != null ? `${Number(top10HitRate * 100).toFixed(1)}%` : '-';
    const maeLabel = maeValue != null ? Number(maeValue).toFixed(2) : '-';

    return (
        <section className="vp-panel p-6">
            <div className="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Evaluatie</div>
                    <h3 className="font-display text-2xl font-semibold tracking-tight text-slate-950">{title}</h3>
                </div>
                <div className="flex items-center gap-3">
                    {evaluation.evaluated_at && (
                        <div className="text-xs uppercase tracking-[0.3em] text-slate-400">
                            Geëvalueerd {evaluation.evaluated_at}
                        </div>
                    )}
                    {collapsible && (
                        <button
                            type="button"
                            onClick={() => setExpanded((prev) => !prev)}
                            className="border border-slate-300 px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em] text-slate-700"
                        >
                            {expanded ? 'Inklappen' : 'Uitklappen'}
                        </button>
                    )}
                </div>
            </div>

            <>
                <div className="grid gap-3 sm:grid-cols-3">
                    <article className="min-w-0 border border-slate-100 bg-slate-50 p-4">
                        <div className="break-words text-[11px] uppercase tracking-[0.16em] text-slate-400">Top 10 hits</div>
                        <div className="mt-2 text-2xl font-semibold text-slate-900">{top10Hits}/10</div>
                        <p className="mt-1 break-words text-[11px] uppercase tracking-[0.12em] text-slate-500">Hitrate {hitRateLabel}</p>
                    </article>
                    <article className="min-w-0 border border-slate-100 bg-slate-50 p-4">
                        <div className="whitespace-nowrap text-[11px] uppercase tracking-[0.12em] text-slate-400">Podiumhits</div>
                        <div className="mt-2 text-2xl font-semibold text-slate-900">{podiumHits}</div>
                        <p className="mt-1 break-words text-[11px] uppercase tracking-[0.12em] text-slate-500">Exact {exactPositionHits}x</p>
                    </article>
                    <article className="min-w-0 border border-slate-100 bg-slate-50 p-4">
                        <div className="break-words text-[11px] uppercase tracking-[0.16em] text-slate-400">Gem. afwijking</div>
                        <div className="mt-2 text-2xl font-semibold text-slate-900">{maeLabel}</div>
                        <div className="mt-1 flex items-center gap-1 text-[11px] uppercase tracking-[0.12em] text-slate-500">
                            <span>MAE</span>
                            <button
                                type="button"
                                className="text-slate-400 hover:text-slate-600"
                                title="MAE (Mean Absolute Error): de gemiddelde afwijking tussen voorspelde en werkelijke positie. Lager is beter."
                                aria-label="Uitleg MAE"
                            >
                                <Info size={11} strokeWidth={2} />
                            </button>
                        </div>
                    </article>
                </div>

                {(!collapsible || expanded) && (
                    <div className="mt-6 border-t border-slate-100 pt-4">
                        <div className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">
                            Actuele top 10 versus voorspelling
                        </div>
                        {actualTop10.length > 0 ? (
                            <div className="mt-3 space-y-3">
                                {actualTop10.map((row) => {
                                    const hasPredictedPosition = row.predicted_position !== null && row.predicted_position !== undefined;
                                    const predictedPosition = hasPredictedPosition ? Number(row.predicted_position) : null;
                                    const gap = hasPredictedPosition ? Math.abs(predictedPosition - row.actual_position) : null;

                                    return (
                                        <div key={row.rider_slug} className="flex items-center justify-between bg-slate-50 px-4 py-3">
                                            <div>
                                                <div className="text-sm font-semibold text-slate-900">
                                                    #{row.actual_position} {row.rider_name}
                                                </div>
                                                <div className="text-xs uppercase tracking-[0.18em] text-slate-500">
                                                    {hasPredictedPosition ? `Voorspeld #${predictedPosition}` : 'Niet voorspeld'}
                                                </div>
                                            </div>
                                            <div className="text-right text-xs font-semibold text-slate-700">{gap !== null ? `Δ ${gap}` : '-'}</div>
                                        </div>
                                    );
                                })}
                            </div>
                        ) : (
                            <p className="mt-3 text-sm text-slate-500">Er is nog geen uitslag om de voorspelling mee te vergelijken.</p>
                        )}
                    </div>
                )}
            </>
        </section>
    );
}
