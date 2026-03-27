import { Head, Link } from '@inertiajs/react';
import PredictionTable from '../Components/PredictionTable';
import RaceList from '../Components/RaceList';
import RiderCard from '../Components/RiderCard';
import SectionHeading from '../Components/SectionHeading';
import AppLayout from '../Layouts/AppLayout';

const stats = [
    { label: 'Signalen', value: '180+', detail: 'Parcours-, vorm- en startlijstinformatie die constant wordt ververst.' },
    { label: 'Uitlegbaarheid', value: '92%', detail: 'Geen black box: elke positie-verschuiwing toont een duidelijke reden.' },
    { label: 'Refresh', value: '5 min', detail: 'Startlijsten en voorspellingen worden om de vijf minuten opnieuw doorgerekend.' },
    { label: 'Kernschermen', value: '3', detail: 'Koersen, renners en voorspellingen blijven in één consistente flow gekoppeld.' },
];

const features = [
    {
        title: 'Koersintelligentie',
        description: 'Combineert parcours, hoogtemeters, koersverloop en ploegcontext tot scenario-signalen nog voor de finale openbreekt.',
        accent: 'from-amber-100 to-orange-50',
    },
    {
        title: 'Uitlegbare AI-scoring',
        description: 'Elke ranking laat zien waarom een renner stijgt of zakt en met welke signalen dat gebeurt.',
        accent: 'from-cyan-100 to-teal-50',
    },
    {
        title: 'Presentatieklare dashboards',
        description: 'Strakke schermen en metrics maken het platform geschikt voor demo, jury en technisch overleg.',
        accent: 'from-slate-100 to-white',
    },
];

const workflow = [
    { step: '01', title: 'Signalen verzamelen', text: 'Koersmetadata, vorm, terreinprofiel en scenariofactoren worden samengebracht in één gestructureerde inputlaag.' },
    { step: '02', title: 'Model doorrekenen', text: 'De rankinglaag screent favorieten, toont onzekerheid en past projecties aan zodra de context verandert.' },
    { step: '03', title: 'Uitlegbaar maken', text: 'Elke voorspelling krijgt context zodat je begrijpt waarom het model die kant op trekt.' },
    { step: '04', title: 'Dieper graven', text: 'Vanuit de homepage spring je naadloos naar koersen, renners en detailvoorspellingen zonder de draad te verliezen.' },
];

const fallbackHeroPredictions = [
    { rider: 'Mathieu van der Poel', reason: 'Explosief op punchy terrein met toppositionering in selectieve finales.', label: 'Terreinfit', signal: 'Korte hellingen + positionering', confidence: '94%' },
    { rider: 'Wout van Aert', reason: 'Gebalanceerde motor en tactische flexibiliteit op gemengde profielen.', label: 'Allround vorm', signal: 'Wind + duurvermogen', confidence: '91%' },
    { rider: 'Tadej Pogacar', reason: 'Zeer hoog plafond zodra hoogteverschil en herhaalde versnellingen de koers openbreken.', label: 'Klimvoordeel', signal: 'Herhaalde demarrages', confidence: '89%' },
];

const fallbackLiveBoard = {
    name: 'E3 Saxo Classic',
    slug: 'e3-harelbeke',
    date: '27 mrt 2026',
    terrain: 'Kasseien',
    category: 'Klassieker',
    confidence: 91,
    leadScenarioTitle: 'Solo of elite groep',
    leadScenarioText: 'Wind en herhaalde versnellingen vergroten de kans dat de beslissende move al voor de laatste 35 kilometer vertrekt.',
    breakPointTitle: 'Breekpunt',
    breakPointText: 'Te laat opschuiven wordt op dit type finale zwaar afgestraft.',
    aiNote: 'Bandendruk, positionering en ploegdiepte wegen hier zwaarder door dan pure sprintsnelheid.',
    entries: fallbackHeroPredictions,
};

const featuredRaces = [
    { slug: 'ronde-van-vlaanderen', name: 'Ronde van Vlaanderen', category: 'Monument', date: '07 apr', summary: 'Kasseistroken, korte hellingen en positionering in de finale bepalen hier de selectie.', distance: '273 km', terrain: 'Kasseien', confidence: '91%', topPick: 'Van der Poel' },
    { slug: 'amstel-gold-race', name: 'Amstel Gold Race', category: 'Klassieker', date: '21 apr', summary: 'Een punchy profiel waar herhaalde versnellingen renners zonder elastiek uit de koers duwen.', distance: '253 km', terrain: 'Heuvelachtig', confidence: '88%', topPick: 'Pogacar' },
    { slug: 'liege-bastogne-liege', name: 'Luik-Bastenaken-Luik', category: 'Monument', date: '28 apr', summary: 'Een lange krachtmeting waarin klimvermogen, timing en vermoeidheidsweerstand het verschil maken.', distance: '258 km', terrain: 'Heuvelachtig', confidence: '93%', topPick: 'Evenepoel' },
];

const featuredRiders = [
    { slug: 'mathieu-van-der-poel', name: 'Mathieu van der Poel', team: 'Alpecin-Deceuninck', profile: 'Aanvallende klassiekerspecialist met elite punch en positionering.', rating: '98', strength: 'Explosieve klassiekers', modelFit: 'Sterk op selectieve eendagskoersen', trend: '3 podiumplaatsen in de laatste 5 topkoersen' },
    { slug: 'remco-evenepoel', name: 'Remco Evenepoel', team: 'Soudal Quick-Step', profile: 'Constante vermogensrenner met solo-dreiging en een sterke tijdritmotor.', rating: '96', strength: 'Langeafstandaanvallen', modelFit: 'Sterk op heuvelachtige duurprofielen', trend: 'Stijgende vorm na hoogtestage' },
    { slug: 'wout-van-aert', name: 'Wout van Aert', team: 'Visma | Lease a Bike', profile: 'Veelzijdige kopman die kan winnen op kracht én koersinzicht.', rating: '97', strength: 'Veelzijdigheid', modelFit: 'Stabiel over meerdere scenario\'s', trend: 'Constante top-10 prestaties' },
];

const destinationCards = [
    {
        title: 'Koersoverzicht',
        description: 'Bekijk de kalender, vergelijk parcoursen en zie waar het model verwacht dat de koers openbreekt.',
        href: '/races',
        action: 'Bekijk koersen',
    },
    {
        title: 'Rennerprofielen',
        description: 'Toon favorieten met heldere metrics, modelinschatting en sterktes die ook niet-technische kijkers begrijpen.',
        href: '/riders',
        action: 'Bekijk renners',
    },
    {
        title: 'Voorspellingsdesk',
        description: 'Open het uitlegbare rankingbord met betrouwbaarheid, tactische signalen en scenario-opbouw.',
        href: '/predictions',
        action: 'Bekijk voorspellingen',
    },
];

export default function Dashboard({ liveBoard = null }) {
    const board = liveBoard ?? fallbackLiveBoard;

    return (
        <AppLayout>
            <Head title="Velopred" />

            <div className="space-y-24">
                <section className="grid items-center gap-10 lg:grid-cols-[1.1fr_0.9fr]">
                    <div className="space-y-8">
                        <span className="vp-pill">AI-ondersteunde wieleranalyse voor koersen, renners en klassementen</span>

                        <div className="space-y-5">
                            <h1 className="font-display text-5xl font-semibold tracking-tight text-slate-950 sm:text-6xl lg:text-7xl">
                                Zie de koers kantelen nog voor de finale openbreekt.
                            </h1>
                            <p className="max-w-2xl text-lg leading-8 text-slate-600 sm:text-xl">
                                Velopred bundelt koersdata, rennerprofielen en uitlegbare AI tot een platform dat voorspelt én uitlegt waarom favorieten stijgen of dalen. Startlijsten, resultaten en modelrankings worden continu gesynchroniseerd, zodat je de koers al kunt doorgronden nog voor de finale openbreekt.
                            </p>
                        </div>

                        <div className="flex flex-col gap-3 sm:flex-row">
                            <Link href="/predictions" className="vp-button-primary">
                                Open voorspellingen
                            </Link>
                            <Link href="/races" className="vp-button-secondary">
                                Bekijk koerskalender
                            </Link>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="vp-panel p-5">
                                <div className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">
                                    Presentatiemodus
                                </div>
                                <p className="mt-3 text-base leading-7 text-slate-600">
                                    Strakke hiërarchie, compacte uitleg en een serieuze visuele taal voor demo en presentatie.
                                </p>
                            </div>
                            <div className="vp-panel p-5">
                                <div className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">
                                    Technische geloofwaardigheid
                                </div>
                                <p className="mt-3 text-base leading-7 text-slate-600">
                                    Laravel, Inertia, React en Tailwind in een echte applicatiestructuur, niet als losse mockup.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div className="relative">
                        <div className="absolute -left-4 top-12 hidden h-24 w-24 rounded-full bg-amber-300/30 blur-2xl sm:block" />
                        <div className="absolute -right-4 bottom-12 hidden h-24 w-24 rounded-full bg-teal-300/30 blur-2xl sm:block" />

                        <div className="vp-panel-dark relative overflow-hidden p-5 sm:p-6">
                            <div className="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-white/30 to-transparent" />
                            <div className="grid gap-5">
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <div className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">
                                            Live koersbord
                                        </div>
                                        <div className="mt-2 font-display text-3xl font-semibold tracking-tight">
                                            {board.name}
                                        </div>
                                        <div className="mt-2 text-sm text-slate-400">
                                            {board.date} • {board.terrain}
                                        </div>
                                    </div>
                                    <div className="rounded-full bg-white/10 px-4 py-2 text-sm font-semibold text-white">
                                        Modelzekerheid {board.confidence}%
                                    </div>
                                </div>

                                <div className="grid gap-4 sm:grid-cols-[1.1fr_0.9fr]">
                                    <div className="rounded-[24px] border border-white/10 bg-white/5 p-4">
                                        <div className="text-xs uppercase tracking-[0.24em] text-slate-400">Hoofdscenario</div>
                                        <div className="mt-3 text-4xl font-semibold text-white">{board.leadScenarioTitle}</div>
                                        <p className="mt-3 text-sm leading-6 text-slate-300">
                                            {board.leadScenarioText}
                                        </p>
                                    </div>

                                    <div className="grid gap-4">
                                        <div className="rounded-[24px] border border-white/10 bg-white/5 p-4">
                                            <div className="text-xs uppercase tracking-[0.24em] text-slate-400">{board.breakPointTitle}</div>
                                            <div className="mt-2 text-sm leading-6 text-slate-300">{board.breakPointText}</div>
                                        </div>
                                        <div className="rounded-[24px] border border-white/10 bg-white/5 p-4">
                                            <div className="text-xs uppercase tracking-[0.24em] text-slate-400">AI-notitie</div>
                                            <div className="mt-2 text-sm leading-6 text-slate-300">
                                                {board.aiNote}
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div className="rounded-[24px] border border-white/10 bg-white/5 p-4">
                                    <div className="flex items-center justify-between gap-4">
                                        <div className="text-sm font-semibold text-white">Geprojecteerde topfavorieten</div>
                                        <div className="text-xs uppercase tracking-[0.24em] text-slate-400">Gebaseerd op startlijstgekoppelde voorspellingen</div>
                                    </div>
                                    <div className="mt-4 space-y-3">
                                        {board.entries.map((entry) => (
                                            <div
                                                key={entry.rider}
                                                className="flex items-center justify-between gap-3 rounded-2xl bg-white/5 px-4 py-3"
                                            >
                                                <div>
                                                    <div className="font-semibold text-white">{entry.rider}</div>
                                                    <div className="text-sm text-slate-400">{entry.signal}</div>
                                                </div>
                                                <div className="text-right">
                                                    <div className="text-xs uppercase tracking-[0.22em] text-slate-500">Betrouwbaarheid</div>
                                                    <div className="text-lg font-semibold text-white">{entry.confidence}</div>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section className="grid gap-4 lg:grid-cols-4">
                    {stats.map((item) => (
                        <div key={item.label} className="vp-panel p-5">
                            <div className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">{item.label}</div>
                            <div className="mt-3 font-display text-4xl font-semibold tracking-tight text-slate-950">{item.value}</div>
                            <p className="mt-3 text-sm leading-6 text-slate-600">{item.detail}</p>
                        </div>
                    ))}
                </section>

                <section id="features" className="space-y-10">
                    <SectionHeading
                        eyebrow="Platformsterktes"
                        title="Een homepage die meteen als product aanvoelt."
                        description="Velopred zit tussen koersanalyse en operationele AI. De opbouw hieronder legt de nadruk op duidelijkheid, vertrouwen en technische degelijkheid."
                    />

                    <div className="grid gap-5 lg:grid-cols-3">
                        {features.map((feature) => (
                            <article
                                key={feature.title}
                                className={`vp-panel bg-gradient-to-br ${feature.accent} p-6`}
                            >
                                <div className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-500">Onderdeel</div>
                                <h3 className="mt-4 font-display text-2xl font-semibold tracking-tight text-slate-950">
                                    {feature.title}
                                </h3>
                                <p className="mt-3 text-sm leading-7 text-slate-600">{feature.description}</p>
                            </article>
                        ))}
                    </div>
                </section>

                <section id="how-it-works" className="grid gap-8 lg:grid-cols-[0.85fr_1.15fr] lg:items-start">
                    <div className="space-y-6">
                        <SectionHeading
                            eyebrow="Werking"
                            title="Opgebouwd rond een uitlegbare AI-flow."
                            description="De structuur brengt je van ruwe koerscontext naar een voorspelling die je ook als mens nog kunt volgen."
                        />

                        <div className="vp-panel-dark p-6">
                            <div className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Modelblik</div>
                            <div className="mt-4 font-display text-3xl font-semibold tracking-tight text-white">
                                Vorm + parcours + tactiek + onzekerheid
                            </div>
                            <p className="mt-4 text-sm leading-7 text-slate-300">
                                De interface toont niet alleen wie vooraan staat, maar ook welke vorm-, parcours- en koerssignalen daaronder zitten.
                            </p>
                        </div>
                    </div>

                    <div className="grid gap-4">
                        {workflow.map((item) => (
                            <article key={item.step} className="vp-panel flex gap-5 p-5 sm:p-6">
                                <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-slate-950 text-sm font-semibold text-white">
                                    {item.step}
                                </div>
                                <div>
                                    <h3 className="font-display text-2xl font-semibold tracking-tight text-slate-950">{item.title}</h3>
                                    <p className="mt-2 text-sm leading-7 text-slate-600">{item.text}</p>
                                </div>
                            </article>
                        ))}
                    </div>
                </section>

                <section className="space-y-10">
                    <SectionHeading
                        eyebrow="Kernmodules"
                        title="Drie duidelijke ingangen in hetzelfde product."
                        description="Elke bestemming volgt dezelfde taal en logica, zodat het platform van homepage tot detailpagina coherent blijft."
                    />

                    <div className="grid gap-5 lg:grid-cols-3">
                        {destinationCards.map((card) => (
                            <article key={card.href} className="vp-panel flex h-full flex-col justify-between p-6">
                                <div>
                                    <div className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Bestemming</div>
                                    <h3 className="mt-4 font-display text-2xl font-semibold tracking-tight text-slate-950">{card.title}</h3>
                                    <p className="mt-3 text-sm leading-7 text-slate-600">{card.description}</p>
                                </div>
                                <Link href={card.href} className="vp-button-secondary mt-8 w-fit">
                                    {card.action}
                                </Link>
                            </article>
                        ))}
                    </div>
                </section>

                <section className="space-y-10">
                    <SectionHeading
                        eyebrow="Voorproef"
                        title="Een voorproef van de koers- en rennerpagina's."
                        description="De homepage geeft al een eerste laag van het platform mee, zonder dat het als losse teaser aanvoelt."
                    />

                    <RaceList races={featuredRaces} />

                    <div className="grid gap-5 lg:grid-cols-3">
                        {featuredRiders.map((rider) => (
                            <RiderCard key={rider.slug} rider={rider} />
                        ))}
                    </div>

                    <PredictionTable entries={board.entries} />
                </section>

                <section className="vp-panel-dark overflow-hidden px-6 py-10 sm:px-8">
                    <div className="grid gap-8 lg:grid-cols-[1fr_auto] lg:items-center">
                        <div>
                            <div className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Klaar om te tonen</div>
                            <h2 className="mt-4 font-display text-4xl font-semibold tracking-tight text-white sm:text-5xl">
                                Gemaakt om zowel een technische jury als een gewone koersvolger mee te krijgen.
                            </h2>
                            <p className="mt-4 max-w-2xl text-base leading-7 text-slate-300">
                                Velopred heeft een duidelijke visuele identiteit, een rustige informatiehiërarchie en demo-klare schermen voor koersen, renners en voorspellingen.
                            </p>
                        </div>

                        <div className="flex flex-col gap-3 sm:flex-row lg:flex-col">
                            <Link href="/races" className="vp-button-secondary bg-white text-slate-950 hover:bg-slate-100">
                                Bekijk koersen
                            </Link>
                            <Link href="/predictions" className="vp-button-primary bg-amber-500 text-slate-950 hover:bg-amber-400">
                                Open voorspellingen
                            </Link>
                        </div>
                    </div>
                </section>
            </div>
        </AppLayout>
    );
}
