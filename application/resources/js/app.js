import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

import { router } from '@inertiajs/vue3';
import { currentDir, currentLocale, syncLocale, t } from './i18n';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

router.on('navigate', (event) => {
    const pageLocale = event.detail.page.props.locale;
    if (pageLocale) {
        syncLocale(pageLocale);
    }
});

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        const initialLocale = props.initialPage.props.locale;
        if (initialLocale) {
            syncLocale(initialLocale);
        }

        const app = createApp({ render: () => h(App, props) });

        app.config.globalProperties.$t = t;
        app.config.globalProperties.$locale = currentLocale;
        app.config.globalProperties.$dir = currentDir;

        return app
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
