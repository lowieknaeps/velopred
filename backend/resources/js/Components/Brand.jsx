function cn(...parts) {
    return parts.filter(Boolean).join(' ');
}

export function BrandMark({ dark = false, className = '' }) {
    return (
        <div
            className={cn(
                'relative flex items-center justify-center overflow-hidden rounded-[20px] border shadow-lg',
                dark
                    ? 'h-12 w-12 border-white/12 bg-white/6 shadow-black/20'
                    : 'h-11 w-11 border-white/70 bg-white shadow-slate-950/10',
                className,
            )}
        >
            <div
                className={cn(
                    'absolute inset-0',
                    dark
                        ? 'bg-[linear-gradient(145deg,rgba(15,118,110,0.28),rgba(15,23,42,0.12)_45%,rgba(232,109,31,0.26))]'
                        : 'bg-[linear-gradient(145deg,rgba(15,118,110,0.14),rgba(255,255,255,0.92)_45%,rgba(232,109,31,0.18))]',
                )}
            />
            <svg viewBox="0 0 64 64" className="relative h-8 w-8" fill="none" aria-hidden="true">
                <path
                    d="M12 20H24"
                    stroke={dark ? 'rgba(226,232,240,0.55)' : 'rgba(15,23,42,0.35)'}
                    strokeWidth="3.5"
                    strokeLinecap="round"
                />
                <path
                    d="M10 28H20"
                    stroke={dark ? 'rgba(226,232,240,0.4)' : 'rgba(15,23,42,0.24)'}
                    strokeWidth="3.5"
                    strokeLinecap="round"
                />
                <path
                    d="M18 14L30 48L43 14"
                    stroke={dark ? '#f8fafc' : '#0f172a'}
                    strokeWidth="6"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                />
                <circle cx="48" cy="17" r="5.5" fill="#e86d1f" />
                <path
                    d="M29.5 47.5L47.5 47.5"
                    stroke={dark ? '#14b8a6' : '#0f766e'}
                    strokeWidth="5"
                    strokeLinecap="round"
                />
            </svg>
        </div>
    );
}

export function BrandLockup({ dark = false, compact = false }) {
    return (
        <div className="flex items-center gap-3">
            <BrandMark dark={dark} />
            <div>
                <div className={cn('font-display font-bold tracking-tight', dark ? 'text-white' : 'text-slate-950', compact ? 'text-base' : 'text-lg')}>
                    Velopred
                </div>
                <div
                    className={cn(
                        'text-xs font-medium uppercase tracking-[0.24em]',
                        dark ? 'text-slate-400' : 'text-slate-500',
                    )}
                >
                    AI race intelligence
                </div>
            </div>
        </div>
    );
}
