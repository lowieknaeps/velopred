export default function ProbabilityBar({ value = 0 }) {
    const pct = Math.max(0, Math.min(100, Number(value) || 0));

    return (
        <div className="vp-prob-bar">
            <div className="vp-prob-fill transition-all duration-300" style={{ width: `${pct}%` }} />
        </div>
    );
}

