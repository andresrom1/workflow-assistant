import './bootstrap';
import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import AppLayout from './layouts/AppLayout.vue';

/**
 * Páginas que NO usan AppLayout (experiencias standalone sin navegación).
 * - Checkout/: flujo mobile-only de inspección fotográfica
 * - Auth/:     login/register (cuando se implemente)
 * - Landing/:  landing pública de marca
 * - Cotizaciones/: vista pública del comparador de coberturas
 */
const LAYOUT_EXCLUDED = ['Checkout/', 'Auth/', 'Landing/', 'Cotizaciones/'];

createInertiaApp({
    resolve: name => {
        const pages = import.meta.glob('./pages/**/*.vue', { eager: true });
        const page = pages[`./pages/${name}.vue`];

        // Asignar AppLayout automáticamente a toda página que:
        //   a) no esté en LAYOUT_EXCLUDED, y
        //   b) no defina su propio layout con defineOptions({ layout: X })
        const excluded = LAYOUT_EXCLUDED.some(prefix => name.startsWith(prefix));
        if (!excluded && page.default.layout === undefined) {
            page.default.layout = AppLayout;
        }

        return page;
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
});
