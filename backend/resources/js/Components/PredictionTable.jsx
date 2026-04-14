import { Link } from '@inertiajs/react';

function PositionBadge({ pos }) {
    const base = 'inline-flex h-7 w-7 items-center justify-center rounded-full text-xs font-bold';

    if (pos === 1) return <span className={`${base} bg-amber-100 text-amber-800`}>{pos}</span>;
    if (pos === 2) return <span className={`${base} bg-slate-100 text-slate-700`}>{pos}</span>;
    if (pos === 3) return <span className={`${base} bg-orange-50 text-orange-700`}>{pos}</span>;

    return <span className={`${base} text-slate-400`}>{pos}</span>;
}

function ActualBadge({ actual, predicted }) {
    if (actual == null) return null;

    const diff = predicted - actual;

    if (diff === 0) return <span className="ml-2 text-xs font-semibold text-green-600">✓ {actual}</span>;
    if (diff > 0) return <span className="ml-2 text-xs font-semibold text-teal-600">↑ {actual}</span>;

    return <span className="ml-2 text-xs font-semibold text-red-400">↓ {actual}</span>;
}

export default function PredictionTable({ predictions = [], entries = null, showActual = false }) {
    const rows = entries ?? predictions;

    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead>
                    <tr className="border-b border-slate-100">
                        <th className="w-10 pb-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">#</th>
                        <th className="pb-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Renner</th>
                        <th className="hidden pb-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-400 sm:table-cell">Ploeg</th>
                        <th className="pb-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Winkans</th>
                        <th className="hidden pb-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-400 md:table-cell">Top-10</th>
                        <th className="hidden pb-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-400 lg:table-cell">Betrouwbaarheid</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-slate-50">
                    {rows.map((prediction) => (
                        <tr
                            key={`${prediction.position}-${prediction.rider_slug ?? prediction.rider}`}
                            className="transition-colors hover:bg-slate-50"
                        >
                            <td className="py-3 pr-3">
                                <PositionBadge pos={prediction.position} />
                            </td>
                            <td className="py-3 font-medium text-slate-900">
                                {prediction.rider_slug ? (
                                    <Link
                                        href={`/riders/${prediction.rider_slug}`}
                                        className="hover:text-indigo-700 hover:underline"
                                    >
                                        {prediction.rider}
                                    </Link>
                                ) : (
                                    prediction.rider
                                )}
                                {showActual && <ActualBadge actual={prediction.actual_position} predicted={prediction.position} />}
                            </td>
                            <td className="hidden py-3 text-slate-500 sm:table-cell">{prediction.team}</td>
                            <td className="py-3 text-right">
                                <div className="flex items-center justify-end gap-2">
                                    <div className="hidden h-1.5 w-20 shrink-0 rounded-full bg-slate-100 sm:block">
                                        <div
                                            className="h-1.5 rounded-full bg-indigo-500"
                                            style={{ width: `${Math.min((prediction.win_probability ?? 0) * 3, 100)}%` }}
                                        />
                                    </div>
                                    <span className="w-14 shrink-0 text-right font-semibold tabular-nums text-slate-900">
                                        {prediction.win_probability}%
                                    </span>
                                </div>
                            </td>
                            <td className="hidden py-3 text-right text-slate-600 md:table-cell">{prediction.top10_probability}%</td>
                            <td className="hidden py-3 text-right lg:table-cell">
                                <span
                                    className={`rounded-full px-2 py-0.5 text-xs font-medium ${
                                        prediction.confidence >= 75
                                            ? 'bg-green-50 text-green-700'
                                            : prediction.confidence >= 60
                                              ? 'bg-amber-50 text-amber-700'
                                              : 'bg-slate-100 text-slate-500'
                                    }`}
                                >
                                    {prediction.confidence}%
                                </span>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
