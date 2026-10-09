import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import './echo';

const pages = import.meta.glob('./pages/**/*.tsx', { eager: true }) as Record<
    string,
    { default: React.ComponentType }
>;

createInertiaApp({
    resolve: (name) => {
        const page = pages[`./pages/${name}.tsx`];

        if (!page) {
            throw new Error(`Página Inertia não encontrada: ${name}`);
        }

        return page.default;
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: {
        color: '#0f766e',
    },
});
