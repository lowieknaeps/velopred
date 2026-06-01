export default function AccuracyStatsSkeleton() {
    return (
        <div className="vp-panel p-5">
            <div className="vp-skeleton h-4 w-44" />
            <div className="mt-4 grid gap-3 sm:grid-cols-3">
                <div className="vp-skeleton h-14" />
                <div className="vp-skeleton h-14" />
                <div className="vp-skeleton h-14" />
            </div>
        </div>
    );
}

