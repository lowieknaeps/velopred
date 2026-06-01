export default function InlineLoadingBadge({ active = false, text = 'Laden...' }) {
    if (!active) return null;

    return (
        <span className="inline-flex items-center gap-2 rounded-full border border-slate-600 bg-slate-900 px-3 py-1 text-xs font-semibold text-slate-200">
            <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-cyan-300" />
            {text}
        </span>
    );
}

