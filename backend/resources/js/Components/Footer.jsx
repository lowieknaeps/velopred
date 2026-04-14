import { Link } from '@inertiajs/react';
import { BrandLockup } from './Brand';

const footerLinks = [
    { label: 'Koersen', href: '/races' },
    { label: 'Renners', href: '/riders' },
    { label: 'Voorspellingen', href: '/predictions' },
    { label: 'Over mij', href: '/over-mij' },
];

export default function Footer() {
    return (
        <footer className="relative mt-20 pb-8 pt-12">
            <div className="vp-panel-dark overflow-hidden px-6 py-8 sm:px-8">
                <div className="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-white/30 to-transparent" />
                <div className="grid gap-8 lg:grid-cols-[1.3fr_0.7fr]">
                    <div className="space-y-5">
                        <span className="vp-pill border-white/10 bg-white/5 text-slate-200">
                            Serieuze koersanalyse voor competitief wielrennen
                        </span>
                        <BrandLockup dark />
                        <div className="max-w-2xl">
                            <h2 className="font-display text-3xl font-semibold tracking-tight sm:text-4xl">
                                Velopred helpt je sneller zien wie waar hoort.
                            </h2>
                            <p className="mt-3 text-base leading-7 text-slate-300">
                                Gebouwd als bachelorproject, maar met een echte app-structuur: koersen, renners,
                                voorspellingen en evaluatie.
                            </p>
                        </div>
                    </div>

                    <div className="space-y-4">
                        <div className="text-sm font-semibold uppercase tracking-[0.24em] text-slate-400">Verkennen</div>
                        <div className="flex flex-wrap gap-3">
                            {footerLinks.map((item) => (
                                <Link
                                    key={item.href}
                                    href={item.href}
                                    className="rounded-full border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-slate-100 transition hover:border-white/20 hover:bg-white/10"
                                >
                                    {item.label}
                                </Link>
                            ))}
                        </div>
                        <p className="text-sm leading-6 text-slate-400">
                            Velopred is een moderne Laravel + Inertia + React-app met ruimte voor echte data,
                            modeloutput en iteratie.
                        </p>
                    </div>
                </div>

                <div className="mt-8 flex flex-col gap-3 border-t border-white/10 pt-6 text-sm text-slate-400 sm:flex-row sm:items-center sm:justify-between">
                    <p>Velopred</p>
                    <p>Koersanalyse en voorspellingen, zonder gedoe.</p>
                </div>
            </div>
        </footer>
    );
}
