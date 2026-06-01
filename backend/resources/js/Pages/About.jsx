import { Head } from '@inertiajs/react';
import AppLayout from '../Layouts/AppLayout';

const steps = [
    {
        title: 'Data ingestie',
        text: 'Racekalender, startlijsten, rennerprofielen en resultaten worden via Laravel-services opgehaald en opgeslagen in de Velopred database.',
    },
    {
        title: 'Feature engineering',
        text: 'Per renner en context worden features opgebouwd: vorm, parcoursfit, veldsterkte, teamdynamiek en historiek per race of etappe.',
    },
    {
        title: 'Parcoursmodel',
        text: 'Velopred gebruikt verschillende modelgroepen per parcours_type en prediction_type (stage, gc, points, kom, youth).',
    },
    {
        title: 'Probabilities',
        text: 'Output wordt als kansverdeling getoond: win probability, top-10 probability en confidence. Dit is beslissingsondersteuning, geen zekerheid.',
    },
];

export default function About() {
    return (
        <AppLayout>
            <Head title="Modeluitleg" />

            <div className="space-y-8">
                <section className="vp-panel p-6 sm:p-8">
                    <div className="vp-pill">Modeluitleg</div>
                    <h1 className="mt-4 font-display text-4xl font-semibold tracking-tight sm:text-5xl">
                        Hoe Velopred voorspelt
                    </h1>
                    <p className="mt-4 max-w-3xl text-sm leading-7 text-slate-300">
                        Velopred combineert wielerdata met feature engineering en probabilistische modellen. De voorspellingen geven kansinschattingen,
                        geen gegarandeerde uitkomsten.
                    </p>
                </section>

                <section className="grid gap-4 md:grid-cols-2">
                    {steps.map((step, index) => (
                        <article key={step.title} className="vp-panel p-6">
                            <div className="text-xs font-semibold uppercase tracking-[0.22em] text-slate-400">Stap {index + 1}</div>
                            <h2 className="mt-3 font-display text-2xl font-semibold">{step.title}</h2>
                            <p className="mt-3 text-sm leading-7 text-slate-300">{step.text}</p>
                        </article>
                    ))}
                </section>

                <section className="vp-panel-dark p-6 sm:p-8">
                    <h2 className="font-display text-3xl font-semibold">Belangrijke termen</h2>
                    <div className="mt-5 grid gap-3 md:grid-cols-2">
                        <div className="rounded-2xl border border-slate-700 bg-slate-900/60 p-4 text-sm text-slate-300">
                            <span className="font-semibold text-slate-100">feature engineering:</span> omzetting van rauwe koersdata naar modelinput.
                        </div>
                        <div className="rounded-2xl border border-slate-700 bg-slate-900/60 p-4 text-sm text-slate-300">
                            <span className="font-semibold text-slate-100">parcours model:</span> modelgedrag afgestemd op raceprofiel en context.
                        </div>
                        <div className="rounded-2xl border border-slate-700 bg-slate-900/60 p-4 text-sm text-slate-300">
                            <span className="font-semibold text-slate-100">win probability:</span> kans dat een renner de context wint.
                        </div>
                        <div className="rounded-2xl border border-slate-700 bg-slate-900/60 p-4 text-sm text-slate-300">
                            <span className="font-semibold text-slate-100">confidence:</span> betrouwbaarheidssignaal van de voorspelling.
                        </div>
                    </div>
                </section>
            </div>
        </AppLayout>
    );
}
