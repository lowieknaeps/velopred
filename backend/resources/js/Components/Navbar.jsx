import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { BrandLockup } from './Brand';

const navLinks = [
    { label: 'Start', href: '/' },
    { label: 'Koersen', href: '/races' },
    { label: 'Renners', href: '/riders' },
    { label: 'Voorspellingen', href: '/predictions' },
    { label: 'Over mij', href: '/over-mij' },
];

export default function Navbar() {
    const [open, setOpen] = useState(false);

    return (
        <header className="sticky top-0 z-30 pt-5">
            <div className="vp-panel mx-auto flex items-center justify-between gap-4 px-4 py-3 sm:px-6">
                <Link href="/" className="flex items-center gap-3">
                    <BrandLockup />
                </Link>

                <nav className="hidden items-center gap-2 md:flex">
                    {navLinks.map((item) => (
                        <Link
                            key={item.href}
                            href={item.href}
                            className="rounded-full px-4 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-100 hover:text-slate-950"
                        >
                            {item.label}
                        </Link>
                    ))}
                </nav>

                <div className="hidden md:block">
                    <Link href="/predictions" className="vp-button-primary">
                        Open voorspellingen
                    </Link>
                </div>

                <button
                    type="button"
                    className="inline-flex h-11 w-11 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-700 md:hidden"
                    onClick={() => setOpen((value) => !value)}
                    aria-label="Navigatie openen"
                >
                    <span className="text-lg font-semibold">{open ? 'X' : '='}</span>
                </button>
            </div>

            {open ? (
                <div className="vp-panel mt-3 space-y-2 px-4 py-4 md:hidden">
                    {navLinks.map((item) => (
                        <Link
                            key={item.href}
                            href={item.href}
                            className="block rounded-2xl px-4 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-100"
                            onClick={() => setOpen(false)}
                        >
                            {item.label}
                        </Link>
                    ))}
                    <Link href="/predictions" className="vp-button-primary flex w-full" onClick={() => setOpen(false)}>
                        Open voorspellingen
                    </Link>
                </div>
            ) : null}
        </header>
    );
}
