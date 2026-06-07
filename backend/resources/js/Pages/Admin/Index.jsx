import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Activity, CheckCircle2, Database, Gauge, ShieldCheck, SlidersHorizontal, TriangleAlert } from 'lucide-react';
import AppLayout from '../../Layouts/AppLayout';

function formatNumber(value) {
    return new Intl.NumberFormat('nl-BE').format(Number(value ?? 0));
}

function formatContext(context) {
    if (context.type === 'stage') {
        return `Etappe ${context.stage_number}`;
    }

    return String(context.type || '').toUpperCase();
}

function StatusBadge({ status }) {
    const ok = status === 'ok';
    return (
        <span className={`inline-flex items-center gap-1 rounded-md border px-2.5 py-1 text-xs font-semibold ${ok ? 'border-emerald-500/35 bg-emerald-500/10 text-emerald-300' : 'border-amber-500/35 bg-amber-500/10 text-amber-300'}`}>
            {ok ? <CheckCircle2 size={13} /> : <TriangleAlert size={13} />}
            {ok ? 'OK' : 'Check'}
        </span>
    );
}

function AdminSettingField({ setting, value, onChange }) {
    if (setting.type === 'boolean') {
        return (
            <label className="flex items-start gap-3 rounded-md border border-slate-700 bg-slate-900/45 p-4">
                <input
                    type="checkbox"
                    checked={Boolean(value)}
                    onChange={(event) => onChange(event.target.checked)}
                    className="mt-1 h-4 w-4 rounded border-slate-600 bg-slate-950 text-blue-500"
                />
                <span>
                    <span className="block text-sm font-semibold text-slate-100">{setting.label}</span>
                    <span className="mt-1 block text-xs leading-5 text-slate-400">{setting.description}</span>
                </span>
            </label>
        );
    }

    if (setting.type === 'text') {
        return (
            <label className="block rounded-md border border-slate-700 bg-slate-900/45 p-4">
                <span className="block text-sm font-semibold text-slate-100">{setting.label}</span>
                <span className="mt-1 block text-xs leading-5 text-slate-400">{setting.description}</span>
                <textarea
                    value={value ?? ''}
                    onChange={(event) => onChange(event.target.value)}
                    rows={3}
                    className="mt-3 w-full rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100 outline-none transition focus:border-blue-400"
                />
            </label>
        );
    }

    return (
        <label className="block rounded-md border border-slate-700 bg-slate-900/45 p-4">
            <span className="block text-sm font-semibold text-slate-100">{setting.label}</span>
            <span className="mt-1 block text-xs leading-5 text-slate-400">{setting.description}</span>
            <input
                type="text"
                value={value ?? ''}
                onChange={(event) => onChange(event.target.value)}
                className="mt-3 w-full rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100 outline-none transition focus:border-blue-400"
            />
        </label>
    );
}

export default function AdminIndex({
    stats = [],
    settings = [],
    modelVersions = [],
    latestContexts = [],
    featureAudit = { keys: [], sampled: 0 },
    releaseChecks = [],
}) {
    const { flash } = usePage().props;
    const initialSettings = Object.fromEntries(settings.map((setting) => [setting.key, setting.value]));
    const { data, setData, patch, processing } = useForm({ settings: initialSettings });

    const updateSetting = (key, value) => {
        setData('settings', {
            ...data.settings,
            [key]: value,
        });
    };

    const saveSettings = (event) => {
        event.preventDefault();
        patch('/admin/settings', { preserveScroll: true });
    };

    return (
        <AppLayout>
            <Head title="Admin" />

            <div className="space-y-8">
                <section className="vp-panel overflow-hidden p-6 sm:p-8">
                    <div className="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                        <div>
                            <div className="vp-pill inline-flex items-center gap-2">
                                <ShieldCheck size={14} />
                                Admin only
                            </div>
                            <h1 className="mt-5 font-display text-4xl font-semibold tracking-tight sm:text-5xl">Velopred control room</h1>
                            <p className="mt-4 max-w-3xl text-sm leading-7 text-slate-300">
                                Beheerlaag voor demo-checks, modelcontext, datakwaliteit en veilige operationele acties. Alleen users met adminrechten kunnen deze pagina openen.
                            </p>
                        </div>
                        <div className="flex flex-wrap gap-3">
                            <button
                                type="button"
                                onClick={() => router.post('/admin/cache/dashboard', {}, { preserveScroll: true })}
                                className="vp-button-secondary"
                            >
                                Dashboard-cache wissen
                            </button>
                            <Link href="/races/dauphine" className="vp-button-primary">
                                Open demo-koers
                            </Link>
                        </div>
                    </div>

                    {flash?.status ? (
                        <div className="mt-5 rounded-md border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">
                            {flash.status}
                        </div>
                    ) : null}
                </section>

                <section className="grid gap-4 md:grid-cols-3 xl:grid-cols-6">
                    {stats.map((stat) => (
                        <div key={stat.key} className="vp-panel p-5">
                            <div className="flex items-center justify-between gap-3">
                                <div className="text-xs uppercase tracking-[0.22em] text-slate-400">{stat.label}</div>
                                <Database size={16} className="text-blue-300" />
                            </div>
                            <div className="mt-3 text-2xl font-semibold text-slate-100">{formatNumber(stat.value)}</div>
                        </div>
                    ))}
                </section>

                <section className="grid gap-5 lg:grid-cols-[0.95fr_1.05fr]">
                    <div className="vp-panel p-6">
                        <div className="mb-5 flex items-center justify-between">
                            <h2 className="inline-flex items-center gap-2 font-display text-2xl font-semibold">
                                <SlidersHorizontal size={18} />
                                Aanpassingen
                            </h2>
                            <span className="vp-accent-pill">veilig</span>
                        </div>
                        <form onSubmit={saveSettings} className="space-y-4">
                            {settings.map((setting) => (
                                <AdminSettingField
                                    key={setting.key}
                                    setting={setting}
                                    value={data.settings?.[setting.key]}
                                    onChange={(value) => updateSetting(setting.key, value)}
                                />
                            ))}
                            <button type="submit" disabled={processing} className="vp-button-primary">
                                {processing ? 'Opslaan...' : 'Instellingen opslaan'}
                            </button>
                        </form>
                    </div>

                    <div className="vp-panel p-6">
                        <div className="mb-5 flex items-center justify-between">
                            <h2 className="inline-flex items-center gap-2 font-display text-2xl font-semibold">
                                <CheckCircle2 size={18} />
                                Releasechecks
                            </h2>
                            <span className="text-xs uppercase tracking-[0.2em] text-slate-400">jury-ready</span>
                        </div>
                        <div className="space-y-3">
                            {releaseChecks.map((check) => (
                                <div key={check.label} className="rounded-md border border-slate-700 bg-slate-900/45 p-4">
                                    <div className="flex items-start justify-between gap-4">
                                        <div>
                                            <div className="font-semibold text-slate-100">{check.label}</div>
                                            <div className="mt-1 text-sm leading-6 text-slate-400">{check.detail}</div>
                                        </div>
                                        <StatusBadge status={check.status} />
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                </section>

                <section className="grid gap-5 xl:grid-cols-[0.8fr_1.2fr]">
                    <div className="vp-panel p-6">
                        <div className="mb-5 flex items-center justify-between">
                            <h2 className="inline-flex items-center gap-2 font-display text-2xl font-semibold">
                                <Gauge size={18} />
                                Modelversies
                            </h2>
                            <span className="vp-warm-pill">{featureAudit.model_version ?? 'onbekend'}</span>
                        </div>
                        <div className="space-y-3">
                            {modelVersions.map((version) => (
                                <div key={version.model_version} className="rounded-md border border-slate-700 bg-slate-900/45 p-4">
                                    <div className="flex items-center justify-between gap-3">
                                        <div className="font-semibold text-slate-100">{version.model_version}</div>
                                        <div className="text-sm text-slate-300">{formatNumber(version.rows)} rijen</div>
                                    </div>
                                    <div className="mt-1 text-xs text-slate-500">Laatste update: {version.latest_update ?? 'onbekend'}</div>
                                </div>
                            ))}
                        </div>
                    </div>

                    <div className="vp-panel p-6">
                        <div className="mb-5 flex items-center justify-between">
                            <h2 className="inline-flex items-center gap-2 font-display text-2xl font-semibold">
                                <Activity size={18} />
                                Laatste prediction-contexten
                            </h2>
                            <span className="text-xs uppercase tracking-[0.2em] text-slate-400">live data</span>
                        </div>
                        <div className="overflow-hidden rounded-md border border-slate-700">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-slate-900/80 text-xs uppercase tracking-[0.18em] text-slate-400">
                                    <tr>
                                        <th className="px-4 py-3">Koers</th>
                                        <th className="px-4 py-3">Context</th>
                                        <th className="px-4 py-3">Model</th>
                                        <th className="px-4 py-3 text-right">Rijen</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-800">
                                    {latestContexts.map((context) => (
                                        <tr key={`${context.slug}-${context.type}-${context.stage_number}-${context.model_version}`}>
                                            <td className="px-4 py-3">
                                                <Link href={`/races/${context.slug}`} className="font-semibold text-slate-100 hover:text-blue-200">
                                                    {context.race}
                                                </Link>
                                                <div className="text-xs text-slate-500">{context.latest_update ?? 'onbekend'}</div>
                                            </td>
                                            <td className="px-4 py-3 text-slate-300">{formatContext(context)}</td>
                                            <td className="px-4 py-3 text-slate-300">{context.model_version}</td>
                                            <td className="px-4 py-3 text-right text-slate-300">{formatNumber(context.rows)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <section className="vp-panel p-6">
                    <div className="mb-5 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <h2 className="inline-flex items-center gap-2 font-display text-2xl font-semibold">
                                <Database size={18} />
                                Feature-audit
                            </h2>
                            <p className="mt-2 text-sm text-slate-400">
                                Sample van {formatNumber(featureAudit.sampled)} prediction-rijen voor {featureAudit.model_version ?? 'laatste model'}.
                            </p>
                        </div>
                        <span className="vp-accent-pill">top feature keys</span>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        {(featureAudit.keys ?? []).map((feature) => (
                            <div key={feature.key} className="rounded-md border border-slate-700 bg-slate-900/45 p-4">
                                <div className="break-words text-sm font-semibold text-slate-100">{feature.key}</div>
                                <div className="mt-2 text-xs text-slate-400">{formatNumber(feature.count)} keer aanwezig</div>
                            </div>
                        ))}
                    </div>
                </section>
            </div>
        </AppLayout>
    );
}
