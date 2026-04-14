import { Head, Link } from '@inertiajs/react';
import PredictionTable from '../Components/PredictionTable';
import RaceList from '../Components/RaceList';
import RiderCard from '../Components/RiderCard';
import SectionHeading from '../Components/SectionHeading';
import AppLayout from '../Layouts/AppLayout';

function buildStats(evaluationSummary) {
    const latest = evaluationSummary?.latest ?? null;
    const recent = evaluationSummary?.recent ?? null;

    const lastRaceLabel = latest?.race?.name ? `Laatste evaluatie: ${latest.race.name}${latest.race.date ? ` (${latest.race.date})` : ''}.` : 'Laatste evaluatie.';
    const evaluatedAt = latest?.evaluated_at ? `Bijgewerkt: ${latest.evaluated_at}.` : '';
    const recentWindow = recent?.count ? `Gemiddeld over de laatste ${recent.count} koersen.` : '';

    return [
        {
            label: 'Top-10 hit',
            value: latest ? `${latest.top10_hits}/10` : '–',
            detail: `${lastRaceLabel} ${recentWindow}`.trim(),
        },
        {
            label: 'Exacte plaatsen',
            value: latest ? `${latest.exact_hits}` : '–',
            detail: latest?.mae !== null && latest?.mae !== undefined ? `Gemiddelde fout (MAE) in top-10: ${latest.mae}. ${evaluatedAt}`.trim() : evaluatedAt || 'Meet hoe vaak de plaats exact klopt binnen de top-10.',
        },
        {
            label: 'Winnaar juist',
            value: latest ? (latest.winner_hit ? 'Ja' : 'Nee') : '–',
            detail: recent?.winner_hit_rate_pct !== undefined ? `Winnaar-hit rate (laatste ${recent.count}): ${recent.winner_hit_rate_pct}%.` : 'Geeft aan of de voorspelde #1 ook won.',
        },
        {
            label: 'Model',
            value: 'Live',
            detail: 'Startlijsten, resultaten en voorspellingen worden automatisch gesynchroniseerd zodra nieuwe data beschikbaar is.',
        },
    ];
}

const features = [
    {
        title: 'Koersintelligentie',
        description: 'Combineert parcours, vorm en ploegcontext tot een inschatting die past bij het type koers.',
        accent: 'from-amber-100 to-orange-50',
    },
    {
        title: 'Uitlegbare modelscore',
        description: 'Je ziet niet alleen wie bovenaan staat, maar ook welke signalen het meeste doorwegen.',
        accent: 'from-cyan-100 to-teal-50',
    },
    {
        title: 'Demo- en presentatieproof',
        description: 'Overzichtelijk, snel en leesbaar. Handig om te tonen, maar ook gewoon om te volgen.',
        accent: 'from-slate-100 to-white',
    },
];

const workflow = [
    { step: '01', title: 'Data ophalen', text: 'Kalender, startlijst, resultaten en rennerprofielen komen binnen via PCS en worden lokaal opgeslagen.' },
    { step: '02', title: 'Model runnen', text: 'Per koers worden features opgebouwd en wordt de ranking opnieuw berekend zodra er updates zijn.' },
    { step: '03', title: 'Uitleg erbij', text: 'Je krijgt context bij de ranking: terreinfit, vorm, koershistoriek en onzekerheid.' },
    { step: '04', title: 'Doorklikken', text: 'Van hieruit ga je direct naar koersen, renners en detailpagina’s.' },
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

const featuredRacesFallback = [
    { slug: 'ronde-van-vlaanderen', name: 'Ronde van Vlaanderen', category: 'Monument', date: '07 apr', summary: 'Kasseistroken, korte hellingen en positionering in de finale bepalen hier de selectie.', distance: '273 km', terrain: 'Kasseien', confidence: '91%', topPick: 'Van der Poel' },
    { slug: 'amstel-gold-race', name: 'Amstel Gold Race', category: 'Klassieker', date: '21 apr', summary: 'Een punchy profiel waar herhaalde versnellingen renners zonder elastiek uit de koers duwen.', distance: '253 km', terrain: 'Heuvelachtig', confidence: '88%', topPick: 'Pogacar' },
    { slug: 'liege-bastogne-liege', name: 'Luik-Bastenaken-Luik', category: 'Monument', date: '28 apr', summary: 'Een lange krachtmeting waarin klimvermogen, timing en vermoeidheidsweerstand het verschil maken.', distance: '258 km', terrain: 'Heuvelachtig', confidence: '93%', topPick: 'Evenepoel' },
];

const featuredRidersFallback = [
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

export default function Dashboard({ liveBoard = null, evaluationSummary = null, featuredRaces = null, featuredRiders = null }) {
    const board = liveBoard ?? fallbackLiveBoard;
    const stats = buildStats(evaluationSummary);
    const previewRaces = Array.isArray(featuredRaces) && featuredRaces.length > 0 ? featuredRaces : featuredRacesFallback;
    const previewRiders = Array.isArray(featuredRiders) && featuredRiders.length > 0 ? featuredRiders : featuredRidersFallback;

    return (
        <AppLayout>
            <Head title="Velopred" />

            <div className="space-y-24">
                <section className="grid items-center gap-10 lg:grid-cols-[1.1fr_0.9fr]">
                    <div className="space-y-8">
                        <span className="vp-pill">Koersdata, voorspellingen en context op 1 plek</span>

                        <div className="space-y-5">
                            <h1 className="font-display text-5xl font-semibold tracking-tight text-slate-950 sm:text-6xl lg:text-7xl">
                                Zie de koers kantelen nog voor de finale openbreekt.
                            </h1>
                            <p className="max-w-2xl text-lg leading-8 text-slate-600 sm:text-xl">
                                Velopred bundelt koersdata en rennerprofielen tot een platform dat voorspelt én toont waarom favorieten stijgen of dalen. Startlijsten en resultaten worden automatisch bijgewerkt, zodat je snel ziet wat er verandert.
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
                                    Rustige lay-out, korte uitleg en duidelijke cijfers. Klaar om te tonen.
                                </p>
                            </div>
                            <div className="vp-panel p-5">
                                <div className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">
                                    Technische geloofwaardigheid
                                </div>
                                <p className="mt-3 text-base leading-7 text-slate-600">
                                    Laravel, Inertia, React en Tailwind in een echte applicatiestructuur.
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
                                        Betrouwbaarheid {board.confidence}%
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
                                            <div className="text-xs uppercase tracking-[0.24em] text-slate-400">Opmerking</div>
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
                        title="Snel overzicht, zonder ruis."
                        description="De homepage is geen teaser: je ziet meteen wat er speelt, en je klikt door waar je wil."
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
                            title="Van data naar voorspelling."
                            description="Je gaat van koerscontext naar een ranking die je ook kunt uitleggen."
                        />

                        <div className="vp-panel-dark p-6">
                            <div className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Modelblik</div>
                            <div className="mt-4 font-display text-3xl font-semibold tracking-tight text-white">
                                Vorm + parcours + tactiek + onzekerheid
                            </div>
                            <p className="mt-4 text-sm leading-7 text-slate-300">
                                Je ziet niet alleen wie vooraan staat, maar ook waarom.
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
                        title="Drie ingangen."
                        description="Koersen, renners en voorspellingen. Zelfde stijl, zelfde logica."
                    />

                    <div className="grid gap-5 lg:grid-cols-3">
                        {destinationCards.map((card) => (
                            <article key={card.href} className="vp-panel flex h-full flex-col justify-between p-6">
                                <div>
                                    <div className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Bestemming</div>
                                    <h3 className="mt-4 break-words font-display text-2xl font-semibold tracking-tight text-slate-950">{card.title}</h3>
                                    <p className="mt-3 break-words text-sm leading-7 text-slate-600">{card.description}</p>
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
                        description="Een paar schermen om snel te zien wat je waar vindt."
                    />

                    <RaceList races={previewRaces} />

                    <div className="grid gap-5 lg:grid-cols-3">
                        {previewRiders.map((rider) => (
                            <RiderCard key={rider.slug} rider={rider} />
                        ))}
                    </div>

                    <PredictionTable entries={board.entries} />
                </section>

                <section className="vp-panel bg-gradient-to-br from-white to-amber-50/40 p-6 sm:p-8">
                    <div className="flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
                        <div className="max-w-2xl">
                            <div className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-500">Laatste koerscheck</div>
                            <h2 className="mt-4 font-display text-3xl font-semibold tracking-tight text-slate-950 sm:text-4xl">
                                Hoe goed zat de top-10 van de laatste koers?
                            </h2>
                            <p className="mt-4 text-sm leading-7 text-slate-600">
                                Na een eendagskoers vergelijken we de voorspelde top-10 met de echte uitslag. Zo zie je meteen waar het goed zat en waar er nog werk is.
                            </p>
                        </div>

                        <div className="grid w-full gap-4 sm:grid-cols-2 lg:max-w-xl">
                            {stats.slice(0, 3).map((item) => (
                                <div key={item.label} className="rounded-[22px] border border-slate-200 bg-white/70 p-5">
                                    <div className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">{item.label}</div>
                                    <div className="mt-3 font-display text-3xl font-semibold tracking-tight text-slate-950">{item.value}</div>
                                    <p className="mt-3 text-sm leading-6 text-slate-600">{item.detail}</p>
                                </div>
                            ))}
                        </div>
                    </div>
                </section>

                <section className="vp-panel-dark overflow-hidden px-6 py-10 sm:px-8">
                    <div className="grid gap-8 lg:grid-cols-[1fr_auto] lg:items-center">
                        <div>
                            <div className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Klaar om te tonen</div>
                            <h2 className="mt-4 font-display text-4xl font-semibold tracking-tight text-white sm:text-5xl">
                                Gemaakt voor zowel een technische jury als een gewone koersvolger.
                            </h2>
                            <p className="mt-4 max-w-2xl text-base leading-7 text-slate-300">
                                Duidelijke schermen, rustige hiërarchie en snelle doorkliks naar detail.
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
