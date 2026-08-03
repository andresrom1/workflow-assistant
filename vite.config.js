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
        // El host que Vite escribe en `public/hot`, de donde Laravel sirve los assets en dev.
        // Hardcodear una IP de LAN acá deja la página en blanco desde cualquier otra máquina
        // apenas la IP cambia — que ya pasó dos veces. Dejarlo sin definir tampoco sirve: Vite
        // escribe `http://[::]:5174`, que el browser no resuelve.
        // Para probar desde el móvil: VITE_DEV_HOST=192.168.0.X npm run dev
        host: env.VITE_DEV_HOST ?? 'localhost',
        port: 5174,
        strictPort: true,
        cors: {
            origin: '*',           // Permite CORS desde cualquier origen
            methods: ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
            allowedHeaders: ['Content-Type', 'Authorization'],
        },
        // Sin bloque `hmr`: el cliente se conecta al mismo host desde el que cargó la página,
        // que es exactamente lo que hace falta en los dos casos (localhost y celular en la LAN).
    },
});
