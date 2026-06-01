import FullScreenBikeWheelLoader from './FullScreenBikeWheelLoader';

const predictionMessages = [
    'Modelinput voorbereiden…',
    'Rennerfeatures berekenen…',
    'Parcoursmodel selecteren…',
    'Kansverdeling genereren…',
    'Voorspelling opslaan…',
];

export default function PredictionGenerationScreen({ active = false, progress = null }) {
    return (
        <FullScreenBikeWheelLoader
            active={active}
            title="Voorspelling genereren"
            progress={progress}
            messages={predictionMessages}
        />
    );
}

