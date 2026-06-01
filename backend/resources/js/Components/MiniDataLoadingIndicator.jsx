import { Activity } from 'lucide-react';

export default function MiniDataLoadingIndicator({ text = 'Data laden...' }) {
    return (
        <span className="inline-flex items-center gap-2 rounded-md border border-slate-700 bg-slate-900 px-3 py-1 text-xs font-semibold text-slate-200">
            <Activity size={13} className="animate-pulse" />
            {text}
        </span>
    );
}

