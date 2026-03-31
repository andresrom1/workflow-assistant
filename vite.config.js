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
            host: '192.168.81.24',  // IP que ven los clientes
            port: 5174,
            protocol: 'ws',        // WebSocket para HMR
        },
    },
});
