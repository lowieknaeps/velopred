import { Head } from '@inertiajs/react';
import AppLayout from '../Layouts/AppLayout';

export default function About() {
    return (
        <AppLayout>
            <Head title="Over mij" />

            <div className="space-y-8">
                <section className="vp-panel p-6 sm:p-8">
                    <span className="vp-pill">Over de maker</span>
                    <h1 className="mt-4 font-display text-4xl font-semibold tracking-tight text-slate-950 sm:text-5xl">
                        Over mij
                    </h1>
                    <p className="mt-4 max-w-3xl text-base leading-7 text-slate-600">
                        Ik ben <strong className="text-slate-900">Lowie Knaeps</strong>. Met Velopred toon ik hoe je wielerdata,
                        modelvoorspellingen en duidelijke product-UX kunt combineren in één platform dat bruikbaar is voor analyse en presentatie.
                    </p>
                </section>

                <section className="grid gap-4 md:grid-cols-3">
                    <article className="vp-panel p-5">
                        <div className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Studie</div>
                        <p className="mt-3 text-sm leading-6 text-slate-600">
                            Bachelorproef met focus op dataverwerking, voorspelmodellen en uitlegbare visualisatie.
                        </p>
                    </article>
                    <article className="vp-panel p-5">
                        <div className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Technologie</div>
                        <p className="mt-3 text-sm leading-6 text-slate-600">
                            Laravel, Inertia, React en Python AI-service met gesynchroniseerde startlijsten en koersresultaten.
                        </p>
                    </article>
                    <article className="vp-panel p-5">
                        <div className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Doel</div>
                        <p className="mt-3 text-sm leading-6 text-slate-600">
                            Betere koersvoorspellingen bouwen die ook voor niet-technische juryleden meteen begrijpbaar zijn.
                        </p>
                    </article>
                </section>
            </div>
        </AppLayout>
    );
}

