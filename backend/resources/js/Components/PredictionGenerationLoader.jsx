export default function PredictionGenerationLoader({ active = false, progress = 0, label = 'Nieuwe voorspelling genereren...' }) {
    if (!active) return null;

    const safeProgress = Math.max(5, Math.min(100, Number(progress) || 5));

    return (
        <div className="vp-panel-dark p-4">
            <div className="flex items-center justify-between gap-3">
                <div className="text-sm font-semibold text-slate-100">{label}</div>
                <div className="text-xs font-semibold text-slate-300">{safeProgress}%</div>
            </div>
            <div className="vp-loading-track mt-3 h-2.5 overflow-hidden rounded-full">
                <div
                    className="h-full rounded-full bg-gradient-to-r from-cyan-400 to-emerald-400 transition-all duration-300"
                    style={{ width: `${safeProgress}%` }}
                />
            </div>
        </div>
    );
}

