import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import PageLoadingOverlay from './PageLoadingOverlay';

function contextFromUrl(url = '') {
    if (url.includes('/predictions')) return 'prediction';
    if (url.includes('/races/')) return 'race';
    if (url.includes('/riders/')) return 'rider';
    if (url === '/' || url.endsWith('/dashboard')) return 'homepage-stats';
    return 'page';
}

function titleFromContext(context) {
    switch (context) {
        case 'prediction':
            return 'Predictions laden';
        case 'race':
            return 'Koersgegevens laden';
        case 'rider':
            return 'Renneranalyse laden';
        case 'homepage-stats':
            return 'Dashboard laden';
        default:
            return 'Pagina laden';
    }
}

export default function GlobalPageLoader() {
    const [active, setActive] = useState(false);
    const [progress, setProgress] = useState(null);
    const [context, setContext] = useState('page');

    useEffect(() => {
        const unbindStart = router.on('start', (event) => {
            const url = event?.detail?.visit?.url?.pathname ?? '';
            setContext(contextFromUrl(url));
            setActive(true);
            setProgress(null);
        });

        const unbindProgress = router.on('progress', (event) => {
            const value = event?.detail?.progress?.percentage;
            if (typeof value === 'number') {
                setProgress(Math.max(6, Math.min(98, value)));
            }
        });

        const stop = () => {
            setTimeout(() => {
                setActive(false);
                setProgress(null);
            }, 140);
        };

        const unbindFinish = router.on('finish', stop);
        const unbindError = router.on('error', stop);

        return () => {
            unbindStart();
            unbindProgress();
            unbindFinish();
            unbindError();
        };
    }, []);

    return (
        <PageLoadingOverlay
            active={active}
            context={context}
            title={titleFromContext(context)}
            progress={progress}
        />
    );
}
