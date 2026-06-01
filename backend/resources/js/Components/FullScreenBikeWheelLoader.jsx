import LoadingMessageRotator from './LoadingMessageRotator';

export default function FullScreenBikeWheelLoader({
    active = false,
    title = 'Laden',
    messages = [],
    progress = null,
}) {
    if (!active) return null;

    return (
        <div className="fixed inset-0 z-[90] flex items-center justify-center vp-loader-backdrop">
            <div className="vp-panel-dark w-[min(92vw,520px)] p-8">
                <div className="mx-auto flex w-fit items-center justify-center">
                    <div className="vp-bike-wheel">
                        <div className="vp-bike-spokes">
                            {Array.from({ length: 10 }).map((_, idx) => (
                                <span
                                    key={idx}
                                    className="vp-bike-spoke"
                                    style={{ transform: `translate(-50%, -100%) rotate(${idx * 36}deg)` }}
                                />
                            ))}
                        </div>
                    </div>
                </div>

                <div className="mt-6 text-center">
                    <div className="font-display text-2xl font-semibold">{title}</div>
                    <div className="mt-3 text-sm text-slate-300">
                        <LoadingMessageRotator active={active} messages={messages} />
                    </div>
                </div>

                {typeof progress === 'number' ? (
                    <div className="mt-5">
                        <div className="vp-loader-line">
                            <span style={{ width: `${Math.max(6, Math.min(100, progress))}%` }} />
                        </div>
                    </div>
                ) : (
                    <div className="mt-5 vp-loader-line">
                        <span />
                    </div>
                )}
            </div>
        </div>
    );
}

