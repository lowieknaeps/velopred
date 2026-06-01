export default function EmptyState({ title, message, action = null }) {
    return (
        <div className="vp-panel p-8 text-center">
            <h3 className="font-display text-2xl font-semibold">{title}</h3>
            <p className="mx-auto mt-3 max-w-xl text-sm leading-6 text-slate-300">{message}</p>
            {action ? <div className="mt-5">{action}</div> : null}
        </div>
    );
}

