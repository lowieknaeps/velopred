import FullScreenBikeWheelLoader from './FullScreenBikeWheelLoader';

const messagesByContext = {
    page: ['Pagina voorbereiden…', 'Racegegevens laden…', 'Data synchroniseren…'],
    race: ['Koersinformatie ophalen…', 'Startlijst controleren…', 'Predictions laden…'],
    rider: ['Rennerprofiel laden…', 'Resultatenhistoriek ophalen…', 'Vormdata verwerken…'],
    prediction: ['Modelinput voorbereiden…', 'Rennerfeatures berekenen…', 'Parcoursmodel selecteren…', 'Kansverdeling genereren…', 'Voorspelling opslaan…'],
    'homepage-stats': ['Overzicht opbouwen…', 'Kerncijfers laden…', 'Modelstatus verifiëren…'],
};

export default function PageLoadingOverlay({ active = false, context = 'page', title = 'Laden', progress = null }) {
    return (
        <FullScreenBikeWheelLoader
            active={active}
            title={title}
            progress={progress}
            messages={messagesByContext[context] ?? messagesByContext.page}
        />
    );
}

