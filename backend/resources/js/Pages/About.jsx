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
                        Mijn naam is Lowie Knaeps en ik ben student Multimedia en Creatieve Technologie in mijn derde bachelor.
                        Binnen mijn opleiding heb ik een sterke interesse ontwikkeld in web development, interactieve toepassingen
                        en de integratie van nieuwe technologieën zoals artificiële intelligentie.
                    </p>
                    <p className="mt-4 max-w-3xl text-base leading-7 text-slate-600">
                        Mijn interesse in wielrennen begon al op jonge leeftijd. Op mijn negende startte ik met competitief
                        wielrennen, een sport die ik tot mijn achttiende actief heb beoefend. Gedurende die jaren heb ik niet
                        alleen fysieke prestaties geleverd, maar ook geleerd hoe belangrijk strategie, vorm en wedstrijdinzicht
                        zijn binnen de sport. Hoewel ik uiteindelijk de keuze maakte om mij volledig te focussen op mijn studies,
                        is mijn passie voor wielrennen nooit verdwenen. Vandaag volg ik de koers nog steeds intensief, zij het
                        vanuit een andere rol: die van toeschouwer en analist.
                    </p>
                    <p className="mt-4 max-w-3xl text-base leading-7 text-slate-600">
                        Parallel met mijn interesse in sport groeide ook mijn passie voor technologie. Tijdens mijn opleiding
                        ontdekte ik vooral een grote interesse in webontwikkeling en het bouwen van gebruiksvriendelijke
                        interfaces. Daarnaast begon artificiële intelligentie mij steeds meer te fascineren, in het bijzonder
                        hoe data kan gebruikt worden om patronen te herkennen en voorspellingen te maken.
                    </p>
                    <p className="mt-4 max-w-3xl text-base leading-7 text-slate-600">
                        Het idee voor Velopred is ontstaan vanuit de combinatie van deze twee passies. Ik wilde een project
                        ontwikkelen dat niet alleen technisch uitdagend is, maar ook persoonlijk relevant. Velopred is daarom
                        een webplatform dat wielerdata verzamelt, analyseert en visualiseert, aangevuld met een AI-model dat
                        voorspellingen genereert over wedstrijden en renners.
                    </p>
                    <p className="mt-4 max-w-3xl text-base leading-7 text-slate-600">
                        Binnen dit project ligt de focus niet enkel op het ontwikkelen van een voorspellingsmodel, maar vooral
                        op het volledige proces er rond: het ophalen en verwerken van data, het ontwerpen van een schaalbare
                        backend, het bouwen van een interactieve frontend en het integreren van een machine learning model in
                        een werkende webapplicatie.
                    </p>
                    <p className="mt-4 max-w-3xl text-base leading-7 text-slate-600">
                        Met Velopred wil ik aantonen hoe data en artificiële intelligentie kunnen toegepast worden binnen de
                        sportwereld, en hoe deze inzichten op een toegankelijke manier gepresenteerd kunnen worden aan gebruikers.
                        Tegelijk vormt dit project voor mij een kans om mijn technische vaardigheden verder te verdiepen en een
                        brug te slaan tussen mijn interesses in technologie en wielrennen.
                    </p>
                </section>

                <section className="grid gap-4 md:grid-cols-3">
                    <article className="vp-panel p-5">
                        <div className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Opleiding</div>
                        <p className="mt-3 text-sm leading-6 text-slate-600">
                            Derde bachelor Multimedia en Creatieve Technologie, met focus op webontwikkeling en AI-integratie.
                        </p>
                    </article>
                    <article className="vp-panel p-5">
                        <div className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Wielrennen</div>
                        <p className="mt-3 text-sm leading-6 text-slate-600">
                            Competitief gekoerst van 9 tot 18 jaar, nu nog steeds intensief betrokken als analist en volger.
                        </p>
                    </article>
                    <article className="vp-panel p-5">
                        <div className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Velopred</div>
                        <p className="mt-3 text-sm leading-6 text-slate-600">
                            Een persoonlijk bachelorproject dat sportdata, AI en bruikbare webinterfaces samenbrengt.
                        </p>
                    </article>
                </section>
            </div>
        </AppLayout>
    );
}
