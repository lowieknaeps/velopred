export default function SectionHeading({ eyebrow, title, description, align = 'left' }) {
    const alignment = align === 'center' ? 'mx-auto max-w-3xl text-center' : 'max-w-2xl';

    return (
        <div className={alignment}>
            {eyebrow ? (
                <div className="mb-4 inline-flex rounded-full border border-slate-700 bg-slate-900 px-4 py-2 text-xs font-semibold uppercase tracking-[0.28em] text-slate-300">
                    {eyebrow}
                </div>
            ) : null}
            <h2 className="font-display text-3xl font-semibold tracking-tight text-amber-500 sm:text-4xl">{title}</h2>
            {description ? <p className="mt-4 text-base leading-7 text-slate-200 sm:text-lg">{description}</p> : null}
        </div>
    );
}
