export default function SectionHeading({ eyebrow, title, description, align = 'left' }) {
    const alignment = align === 'center' ? 'mx-auto max-w-3xl text-center' : 'max-w-2xl';

    return (
        <div className={alignment}>
            {eyebrow ? (
                <div className="mb-4 inline-flex rounded-full bg-white/80 px-4 py-2 text-xs font-semibold uppercase tracking-[0.28em] text-slate-500 shadow-sm">
                    {eyebrow}
                </div>
            ) : null}
            <h2 className="font-display text-3xl font-semibold tracking-tight text-slate-950 sm:text-4xl">{title}</h2>
            {description ? <p className="mt-4 text-base leading-7 text-slate-600 sm:text-lg">{description}</p> : null}
        </div>
    );
}
