import { Head } from '@inertiajs/react';
import RaceList from '../../Components/RaceList';
import SectionHeading from '../../Components/SectionHeading';
import AppLayout from '../../Layouts/AppLayout';

export default function RacesIndex({ highlights = [], ongoing = [], upcoming = [], recentPast = [], lastYear = [] }) {
    return (
        <AppLayout>
            <Head title="Koersen" />

            <div className="space-y-12">
                <SectionHeading
                    eyebrow="Racekalender"
                    title="Alle WorldTour en ProSeries wedstrijden van het seizoen."
                    description="Per koers zie je het terreinprofiel, de AI-topfavoriet en de berekende winkans — direct vanuit de historische data."
                />

                {/* Highlights */}
                <div className="grid gap-4 lg:grid-cols-3">
                    {highlights.map((item) => (
                        <article key={item.label} className="vp-panel p-5">
                            <div className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">{item.label}</div>
                            <div className="mt-3 font-display text-3xl font-semibold tracking-tight text-slate-950">{item.value}</div>
                            <p className="mt-3 text-sm leading-6 text-slate-600">{item.text}</p>
                        </article>
                    ))}
                </div>

                {/* Nu bezig */}
                {ongoing.length > 0 && (
                    <section className="space-y-5">
                        <div className="flex items-center gap-3">
                            <span className="relative flex h-2.5 w-2.5">
                                <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-green-400 opacity-75" />
                                <span className="relative inline-flex h-2.5 w-2.5 rounded-full bg-green-500" />
                            </span>
                            <h2 className="font-display text-xl font-semibold text-slate-950">Nu bezig</h2>
                        </div>
                        <RaceList races={ongoing} />
                    </section>
                )}

                {/* Komende wedstrijden */}
                {upcoming.length > 0 && (
                    <section className="space-y-5">
                        <div className="flex items-center justify-between">
                            <h2 className="font-display text-xl font-semibold text-slate-950">
                                Komende wedstrijden
                            </h2>
                            <span className="text-sm text-slate-400">{upcoming.length} races</span>
                        </div>
                        <RaceList races={upcoming} />
                    </section>
                )}

                {/* Afgelopen dit seizoen */}
                {recentPast.length > 0 && (
                    <section className="space-y-5">
                        <div className="flex items-center justify-between">
                            <h2 className="font-display text-xl font-semibold text-slate-950">
                                Afgelopen dit seizoen
                            </h2>
                            <span className="text-sm text-slate-400">{recentPast.length} races</span>
                        </div>
                        <RaceList races={recentPast} />
                    </section>
                )}

                {/* Vorig seizoen */}
                {lastYear.length > 0 && (
                    <section className="space-y-5">
                        <div className="flex items-center justify-between">
                            <h2 className="font-display text-xl font-semibold text-slate-950">
                                Vorig seizoen
                            </h2>
                            <span className="text-sm text-slate-400">Ter referentie</span>
                        </div>
                        <RaceList races={lastYear} />
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
