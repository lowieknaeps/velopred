export function RaceCardSkeleton() {
    return (
        <div className="vp-panel p-5">
            <div className="vp-skeleton h-3 w-28" />
            <div className="vp-skeleton mt-4 h-8 w-3/4" />
            <div className="vp-skeleton mt-3 h-3 w-full" />
            <div className="vp-skeleton mt-2 h-3 w-5/6" />
            <div className="mt-6 grid grid-cols-3 gap-3">
                <div className="vp-skeleton h-14" />
                <div className="vp-skeleton h-14" />
                <div className="vp-skeleton h-14" />
            </div>
        </div>
    );
}

export function PredictionTableSkeleton({ rows = 8 }) {
    return (
        <div className="vp-panel p-5">
            <div className="vp-skeleton h-4 w-44" />
            <div className="mt-5 space-y-3">
                {Array.from({ length: rows }).map((_, idx) => (
                    <div key={idx} className="grid grid-cols-[40px_1fr_100px] items-center gap-3">
                        <div className="vp-skeleton h-7 w-7 rounded-full" />
                        <div className="vp-skeleton h-10" />
                        <div className="vp-skeleton h-4 w-full" />
                    </div>
                ))}
            </div>
        </div>
    );
}

export function RiderProfileSkeleton() {
    return (
        <div className="vp-panel p-6">
            <div className="vp-skeleton h-4 w-24" />
            <div className="vp-skeleton mt-4 h-10 w-2/3" />
            <div className="vp-skeleton mt-4 h-3 w-full" />
            <div className="vp-skeleton mt-2 h-3 w-5/6" />
            <div className="mt-6 grid grid-cols-3 gap-3">
                <div className="vp-skeleton h-16" />
                <div className="vp-skeleton h-16" />
                <div className="vp-skeleton h-16" />
            </div>
        </div>
    );
}

