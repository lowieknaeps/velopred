export default function PredictionEvaluationPanel({ evaluation, title = 'Model versus uitslag' }) {
    if (!evaluation) {
        return null;
    }

    const hitRate = evaluation.top10_hit_rate != null
        ? `${Number((evaluation.top10_hit_rate * 100).toFixed(1))}%`
        : '–';
    const mae = evaluation.mean_absolute_position_error != null
        ? Number(evaluation.mean_absolute_position_error).toFixed(2)
        : '–';
    const actualTop10 = evaluation.actual_top10 ?? [];

    return (
        <section className="vp-panel p-6">
            <div className="mb-4 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Evaluatie</div>
                    <h3 className="font-display text-2xl font-semibold tracking-tight text-slate-950">{title}</h3>
                </div>
                {evaluation.evaluated_at && (
                    <div className="text-xs uppercase tracking-[0.3em] text-slate-400">
                        Geëvalueerd {evaluation.evaluated_at}
                    </div>
                )}
            </div>

            <div className="grid gap-3 sm:grid-cols-3">
                <article className="rounded-2xl border border-slate-100 bg-slate-50 p-4">
                    <div className="text-xs uppercase tracking-[0.24em] text-slate-400">Top 10 hits</div>
                    <div className="mt-2 text-2xl font-semibold text-slate-900">
                        {evaluation.top10_hits ?? 0}/10
                    </div>
                    <p className="mt-1 text-xs uppercase tracking-[0.2em] text-slate-500">
                        Hit rate {hitRate}
                    </p>
                </article>
                <article className="rounded-2xl border border-slate-100 bg-slate-50 p-4">
                    <div className="text-xs uppercase tracking-[0.24em] text-slate-400">Podiumhits</div>
                    <div className="mt-2 text-2xl font-semibold text-slate-900">
                        {evaluation.podium_hits ?? 0}
                    </div>
                    <p className="mt-1 text-xs uppercase tracking-[0.2em] text-slate-500">
                        Exact {evaluation.exact_position_hits ?? 0}x in top 10
                    </p>
                </article>
                <article className="rounded-2xl border border-slate-100 bg-slate-50 p-4">
                    <div className="text-xs uppercase tracking-[0.24em] text-slate-400">Gemiddelde afwijking</div>
                    <div className="mt-2 text-2xl font-semibold text-slate-900">{mae}</div>
                    <p className="mt-1 text-xs uppercase tracking-[0.2em] text-slate-500">
                        Mean absolute error
                    </p>
                </article>
            </div>

            <div className="mt-6 border-t border-slate-100 pt-4">
                <div className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">
                    Actuele top 10 versus voorspelling
                </div>
                {actualTop10.length > 0 ? (
                    <div className="mt-3 space-y-3">
                        {actualTop10.map((row) => {
                            const hasPredictedPosition = row.predicted_position !== null && row.predicted_position !== undefined;
                            const predictedPosition = hasPredictedPosition ? Number(row.predicted_position) : null;
                            const gap = hasPredictedPosition
                                ? Math.abs(predictedPosition - row.actual_position)
                                : null;

                            return (
                                <div
                                    key={row.rider_slug}
                                    className="flex items-center justify-between rounded-2xl bg-slate-50 px-4 py-3"
                                >
                                    <div>
                                        <div className="text-sm font-semibold text-slate-900">
                                            #{row.actual_position} {row.rider_name}
                                        </div>
                                        <div className="text-xs uppercase tracking-[0.18em] text-slate-500">
                                            {hasPredictedPosition
                                                ? `Voorspeld #${predictedPosition}`
                                                : 'Niet voorspeld'}
                                        </div>
                                    </div>
                                    <div className="text-right text-xs font-semibold text-slate-700">
                                        {gap !== null ? `Δ ${gap}` : '—'}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                ) : (
                    <p className="mt-3 text-sm text-slate-500">
                        Er is nog geen uitslag om de voorspelling mee te vergelijken.
                    </p>
                )}
            </div>
        </section>
    );
}
