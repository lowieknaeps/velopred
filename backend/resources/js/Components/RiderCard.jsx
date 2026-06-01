import { Link } from '@inertiajs/react';

export default function RiderCard({ rider }) {
    return (
        <article className="vp-panel p-5 transition hover:-translate-y-0.5">
            <div className="flex items-start justify-between gap-4">
                <div className="min-w-0">
                    <div className="text-xs uppercase tracking-[0.22em] text-slate-400">{rider.team || 'Onbekend team'}</div>
                    <h3 className="mt-2 break-words font-display text-2xl font-semibold">{rider.name}</h3>
                    <p className="mt-2 text-sm text-slate-300">{rider.profile || 'Modeldata ontbreekt voor deze renner.'}</p>
                </div>
                <div className="rounded-xl border border-slate-700 bg-slate-900 px-3 py-2 text-right">
                    <div className="text-[11px] uppercase tracking-[0.2em] text-slate-400">{rider.ratingLabel || 'Ranking'}</div>
                    <div className="text-lg font-semibold text-slate-100">{rider.rating ?? 'N/A'}</div>
                </div>
            </div>

            <div className="mt-5 grid gap-3 sm:grid-cols-2">
                <div className="rounded-xl border border-slate-700 bg-slate-900/70 p-3">
                    <div className="text-[11px] uppercase tracking-[0.2em] text-slate-400">{rider.strengthLabel || 'Signaal'}</div>
                    <div className="mt-1 text-sm font-semibold text-slate-100">{rider.strength || 'Geen data'}</div>
                </div>
                <div className="rounded-xl border border-slate-700 bg-slate-900/70 p-3">
                    <div className="text-[11px] uppercase tracking-[0.2em] text-slate-400">{rider.modelFitLabel || 'Modelfit'}</div>
                    <div className="mt-1 text-sm font-semibold text-slate-100">{rider.modelFit || 'Geen data'}</div>
                </div>
            </div>

            <div className="mt-5 flex items-center justify-between gap-4">
                <div className="min-w-0">
                    <div className="text-xs uppercase tracking-[0.22em] text-slate-400">{rider.trendLabel || 'Trend'}</div>
                    <div className="mt-1 text-sm font-semibold text-slate-100">{rider.trend || 'Niet beschikbaar'}</div>
                </div>
                <Link href={`/riders/${rider.slug}`} className="vp-button-secondary">
                    Profiel
                </Link>
            </div>
        </article>
    );
}
