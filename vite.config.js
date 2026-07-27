import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import { env } from 'node:process';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    resolve: {
        alias: {
            '@': '/resources/js',
        },
    },
    server: {
        port: 5174,
        // para poder ingresar desde el movil en local
        strictPort: true,
        cors: {
            origin: '*',           // Permite CORS desde cualquier origen
            methods: ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
            allowedHeaders: ['Content-Type', 'Authorization'],
        },
        hmr: {
            // Sin VITE_HMR_HOST, Vite usa el host de la request. Hardcodear una IP de LAN
            // hace que `public/hot` apunte ahí y que la página cargue en blanco desde
            // cualquier otra máquina apenas cambia la IP. Para probar desde el móvil:
            // VITE_HMR_HOST=192.168.0.X npm run dev
            host: env.VITE_HMR_HOST,
            port: 5174,
            protocol: 'ws',        // WebSocket para HMR
        },
    },
});
