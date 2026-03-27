import './bootstrap';
import '../css/app.css';
import { createInertiaApp } from '@inertiajs/react';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';

createInertiaApp({
    title: (title) => (title ? `${title} | Velopred` : 'Velopred'),
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.jsx', { eager: true });
        return pages[`./Pages/${name}.jsx`];
    },
    setup({ el, App, props }) {
        createRoot(el).render(
            <StrictMode>
                <App {...props} />
            </StrictMode>
        );
    },
});
