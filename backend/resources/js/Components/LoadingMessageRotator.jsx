import { useEffect, useMemo, useState } from 'react';

export default function LoadingMessageRotator({ messages = [], active = false, intervalMs = 1400 }) {
    const [index, setIndex] = useState(0);

    useEffect(() => {
        if (!active || messages.length <= 1) return undefined;
        const id = window.setInterval(() => setIndex((v) => (v + 1) % messages.length), intervalMs);
        return () => window.clearInterval(id);
    }, [active, intervalMs, messages.length]);

    const text = useMemo(() => {
        if (messages.length === 0) return 'Data laden...';
        return messages[index % messages.length];
    }, [index, messages]);

    return <span>{text}</span>;
}

