export default function PageTransitionOverlay({ active = false, label = 'Race data laden...' }) {
    if (!active) return null;

    return (
        <div className="pointer-events-none fixed inset-0 z-40 flex items-center justify-center bg-slate-950/35 backdrop-blur-[2px]">
            <div className="vp-panel-dark w-[min(92vw,440px)] p-5">
                <div className="flex items-center justify-between">
                    <div className="text-sm font-semibold text-slate-100">Velopred</div>
                    <div className="text-xs uppercase tracking-[0.2em] text-slate-400">Loading</div>
                </div>
                <p className="mt-3 text-sm text-slate-300">{label}</p>
                <div className="vp-loading-track mt-4 h-2.5 overflow-hidden rounded-full">
                    <div className="h-full w-2/3 animate-pulse rounded-full bg-gradient-to-r from-cyan-400 to-emerald-400" />
                </div>
            </div>
        </div>
    );
}

