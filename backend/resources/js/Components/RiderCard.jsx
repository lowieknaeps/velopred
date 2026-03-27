import { Link } from '@inertiajs/react';

export default function RiderCard({ rider }) {
    return (
        <article className="vp-panel p-5 transition duration-300 hover:-translate-y-1 hover:shadow-[0_28px_80px_-40px_rgba(15,23,42,0.35)]">
            <div className="flex items-start justify-between gap-4">
                <div>
                    <div className="text-xs font-semibold uppercase tracking-[0.22em] text-slate-400">{rider.team}</div>
                    <h3 className="mt-2 font-display text-2xl font-semibold tracking-tight text-slate-950">{rider.name}</h3>
                    <p className="mt-1 text-sm text-slate-600">{rider.profile}</p>
                </div>
                <div className="rounded-2xl bg-slate-950 px-3 py-2 text-right text-white">
                    <div className="text-[11px] uppercase tracking-[0.22em] text-slate-300">{rider.ratingLabel ?? 'Ranking'}</div>
                    <div className="text-lg font-semibold">{rider.rating}</div>
                </div>
            </div>

            <div className="mt-6 grid grid-cols-2 gap-3">
                <div className="rounded-2xl bg-slate-50 p-3">
                    <div className="text-xs uppercase tracking-[0.22em] text-slate-400">{rider.strengthLabel ?? 'Sterkte'}</div>
                    <div className="mt-1 text-sm font-semibold text-slate-900">{rider.strength}</div>
                </div>
                <div className="rounded-2xl bg-teal-50 p-3">
                    <div className="text-xs uppercase tracking-[0.22em] text-teal-700">{rider.modelFitLabel ?? 'Modelinschatting'}</div>
                    <div className="mt-1 text-sm font-semibold text-teal-950">{rider.modelFit}</div>
                </div>
            </div>

            <div className="mt-6 flex items-center justify-between gap-4">
                <div>
                    <div className="text-xs uppercase tracking-[0.22em] text-slate-400">{rider.trendLabel ?? 'Recente trend'}</div>
                    <div className="mt-1 text-sm font-semibold text-slate-900">{rider.trend}</div>
                </div>
                <Link
                    href={`/riders/${rider.slug}`}
                    className="rounded-full border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-950 hover:bg-slate-950 hover:text-white"
                >
                    Profiel
                </Link>
            </div>
        </article>
    );
}
