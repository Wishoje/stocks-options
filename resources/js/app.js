import './bootstrap';
import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { initGA, trackPageView } from './lib/ga';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        const app = createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);

        const gaId = props.initialPage?.props?.marketing?.ga4_id;
        initGA(gaId);
        trackPageView(window.location.pathname + window.location.search);

        router.on('navigate', (event) => {
            const url = event?.detail?.page?.url;
            if (url) trackPageView(url);
        });

        return app;
    },
    progress: {
        color: '#4B5563',
    },
});
