import { Head } from '@inertiajs/react';
import AppLayout from '../Layouts/AppLayout';

const pillars = [
    {
        title: 'Opleiding',
        value: '3e bachelor',
        text: 'Multimedia en Creatieve Technologie met focus op webdevelopment, interactieve toepassingen en AI-integratie.',
        accent: 'from-cyan-100 to-sky-50',
    },
    {
        title: 'Wielerachtergrond',
        value: '9 → 18 jaar',
        text: 'Competitief gekoerst in de jeugd, met blijvende passie voor koersanalyse, vorm en wedstrijdinzicht.',
        accent: 'from-amber-100 to-orange-50',
    },
    {
        title: 'Velopred',
        value: 'Bachelorproject',
        text: 'Een werkende webapp die data, modeloutput en heldere visualisatie samenbrengt in een bruikbaar platform.',
        accent: 'from-slate-100 to-white',
    },
];

const journey = [
    {
        year: 'Jeugd',
        title: 'Wielrennen als basis',
        text: 'Op mijn negende begon ik met competitief wielrennen en leerde ik hoe bepalend strategie, timing en vorm zijn in een wedstrijd.',
    },
    {
        year: 'Opleiding',
        title: 'Technologie als richting',
        text: 'Tijdens mijn studie groeide mijn passie voor webinterfaces en voor datagedreven systemen die patronen zichtbaar maken.',
    },
    {
        year: 'Nu',
        title: 'Velopred als brug',
        text: 'Velopred combineert mijn sportervaring met technische skills in één project dat relevant is voor zowel gebruikers als jury.',
    },
];

export default function About() {
    return (
        <AppLayout>
            <Head title="Over mij" />

            <div className="space-y-10">
                <section className="relative overflow-hidden rounded-[34px] border border-slate-200 bg-gradient-to-br from-slate-950 via-slate-900 to-cyan-950 p-7 text-white sm:p-10">
                    <div className="absolute -right-14 -top-14 h-40 w-40 rounded-full bg-cyan-300/20 blur-2xl" />
                    <div className="absolute -left-10 bottom-0 h-32 w-32 rounded-full bg-amber-300/20 blur-2xl" />
                    <div className="grid items-center gap-8 lg:grid-cols-[1.1fr_0.9fr]">
                        <div>
                            <span className="vp-pill border-white/20 bg-white/10 text-white">Over de maker</span>
                            <h1 className="mt-4 max-w-3xl font-display text-4xl font-semibold tracking-tight sm:text-6xl">
                                Technologie en koers, samengebracht in Velopred.
                            </h1>
                            <p className="mt-5 max-w-3xl text-base leading-7 text-slate-200 sm:text-lg">
                                Mijn naam is Lowie Knaeps en ik ben student Multimedia en Creatieve Technologie in mijn derde bachelor.
                                Binnen mijn opleiding heb ik een sterke interesse ontwikkeld in web development, interactieve toepassingen
                                en de integratie van nieuwe technologieën zoals artificiële intelligentie.
                            </p>
                        </div>
                        <div className="mx-auto w-full max-w-md">
                            <img
                                src="/images/about/heroimage.jpg"
                                alt="Lowie Knaeps tijdens wielerwedstrijd"
                                className="h-[340px] w-full rounded-[26px] border border-white/20 object-cover shadow-2xl shadow-slate-950/40"
                            />
                        </div>
                    </div>
                </section>

                <section className="grid gap-4 md:grid-cols-3">
                    {pillars.map((item) => (
                        <article key={item.title} className={`vp-panel bg-gradient-to-br ${item.accent} p-5`}>
                            <div className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-500">{item.title}</div>
                            <div className="mt-3 font-display text-3xl font-semibold tracking-tight text-slate-950">{item.value}</div>
                            <p className="mt-3 text-sm leading-6 text-slate-600">{item.text}</p>
                        </article>
                    ))}
                </section>

                <section className="grid gap-6 lg:grid-cols-[1.05fr_0.95fr]">
                    <article className="vp-panel p-6 sm:p-7">
                        <h2 className="font-display text-3xl font-semibold tracking-tight text-slate-950">Mijn verhaal</h2>
                        <p className="mt-4 text-base leading-7 text-slate-600">
                            Mijn interesse in wielrennen begon al op jonge leeftijd. Op mijn negende startte ik met competitief
                            wielrennen, een sport die ik tot mijn achttiende actief heb beoefend. Gedurende die jaren heb ik niet
                            alleen fysieke prestaties geleverd, maar ook geleerd hoe belangrijk strategie, vorm en wedstrijdinzicht
                            zijn binnen de sport.
                        </p>
                        <p className="mt-4 text-base leading-7 text-slate-600">
                            Hoewel ik uiteindelijk de keuze maakte om mij volledig te focussen op mijn studies, is mijn passie voor
                            wielrennen nooit verdwenen. Vandaag volg ik de koers nog steeds intensief, zij het vanuit een andere rol:
                            die van toeschouwer en analist.
                        </p>
                        <p className="mt-4 text-base leading-7 text-slate-600">
                            Parallel met mijn interesse in sport groeide ook mijn passie voor technologie. Tijdens mijn opleiding
                            ontdekte ik vooral een grote interesse in webontwikkeling en het bouwen van gebruiksvriendelijke
                            interfaces. Daarnaast begon artificiële intelligentie mij steeds meer te fascineren, in het bijzonder hoe
                            data kan gebruikt worden om patronen te herkennen en voorspellingen te maken.
                        </p>
                    </article>

                    <article className="vp-panel p-6 sm:p-7">
                        <h2 className="font-display text-3xl font-semibold tracking-tight text-slate-950">Van idee naar platform</h2>
                        <div className="mt-6 space-y-4">
                            {journey.map((step) => (
                                <div key={step.year} className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                    <div className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-500">{step.year}</div>
                                    <div className="mt-1 font-semibold text-slate-900">{step.title}</div>
                                    <p className="mt-2 text-sm leading-6 text-slate-600">{step.text}</p>
                                </div>
                            ))}
                        </div>
                    </article>
                </section>

                <section className="vp-panel p-6 sm:p-8">
                    <div className="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <div className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Toen ik koerste</div>
                            <h2 className="mt-2 font-display text-3xl font-semibold tracking-tight text-slate-950">
                                Van jonge renner tot laatste seizoen
                            </h2>
                        </div>
                    </div>
                    <div className="mt-6 grid gap-5 md:grid-cols-2">
                        <article className="overflow-hidden rounded-[24px] border border-slate-200 bg-slate-50">
                            <img
                                src="/images/about/young.jpg"
                                alt="Lowie Knaeps als jonge renner"
                                className="h-[290px] w-full object-cover"
                            />
                            <div className="p-4">
                                <div className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-500">Beginjaren</div>
                                <p className="mt-2 text-sm leading-6 text-slate-600">
                                    De periode waarin koersinzicht, discipline en wedstrijdgevoel zijn gevormd.
                                </p>
                            </div>
                        </article>
                        <article className="overflow-hidden rounded-[24px] border border-slate-200 bg-slate-50">
                            <img
                                src="/images/about/old.jpg"
                                alt="Lowie Knaeps in laatste koersjaar"
                                className="h-[290px] w-full object-cover"
                            />
                            <div className="p-4">
                                <div className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-500">Laatste jaar</div>
                                <p className="mt-2 text-sm leading-6 text-slate-600">
                                    De overgang naar analyse en technologie, met dezelfde passie voor de koers.
                                </p>
                            </div>
                        </article>
                    </div>
                </section>

                <section className="vp-panel-dark p-6 sm:p-8">
                    <h2 className="font-display text-3xl font-semibold tracking-tight">Waarom Velopred?</h2>
                    <p className="mt-4 max-w-4xl text-base leading-7 text-slate-300">
                        Het idee voor Velopred is ontstaan vanuit de combinatie van mijn twee passies. Ik wilde een project
                        ontwikkelen dat niet alleen technisch uitdagend is, maar ook persoonlijk relevant.
                    </p>
                    <p className="mt-4 max-w-4xl text-base leading-7 text-slate-300">
                        Binnen dit project ligt de focus niet enkel op het ontwikkelen van een voorspellingsmodel, maar vooral op het
                        volledige proces er rond: het ophalen en verwerken van data, het ontwerpen van een schaalbare backend, het
                        bouwen van een interactieve frontend en het integreren van een machine learning model in een werkende
                        webapplicatie.
                    </p>
                    <p className="mt-4 max-w-4xl text-base leading-7 text-slate-300">
                        Met Velopred wil ik aantonen hoe data en artificiële intelligentie kunnen toegepast worden binnen de
                        sportwereld, en hoe deze inzichten op een toegankelijke manier gepresenteerd kunnen worden aan gebruikers.
                        Tegelijk vormt dit project voor mij een kans om mijn technische vaardigheden verder te verdiepen en een brug te
                        slaan tussen mijn interesses in technologie en wielrennen.
                    </p>
                </section>
            </div>
        </AppLayout>
    );
}
