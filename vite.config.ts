import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { globSync } from 'node:fs';
import path from 'path';
import { defineConfig, loadEnv } from 'vite';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const parsedVitePort = Number(env.VITE_PORT);
    const vitePort = Number.isFinite(parsedVitePort) && parsedVitePort > 0 ? parsedVitePort : 5173;
    // The production image generates the Wayfinder files in its PHP stage; its Node stage has no PHP to run the plugin.
    const wayfinderTypesArePreGenerated = process.env.WAYFINDER_TYPES_PREGENERATED === 'true';
    // A Filament panel theme (`php artisan make:filament-theme`) is a Vite entry of its own; every one that exists is built.
    const filamentThemes = globSync('resources/css/filament/*/theme.css').sort();

    return {
        server: {
            host: '0.0.0.0',
            cors: true,
            strictPort: true,
            port: 5173,
            hmr: {
                host: 'localhost',
                protocol: 'ws',
                clientPort: vitePort,
            },
            watch: {
                ignored: ['**/storage/framework/views/**'],
            },
        },
        plugins: [
            laravel({
                input: ['resources/js/app.ts', ...filamentThemes],
                refresh: true,
            }),
            inertia(),
            tailwindcss(),
            vue({
                template: {
                    transformAssetUrls: {
                        base: null,
                        includeAbsolute: false,
                    },
                },
            }),
            wayfinderTypesArePreGenerated ? null : wayfinder({ formVariants: true }),
        ],
        resolve: {
            alias: {
                '@': path.resolve(import.meta.dirname, './resources/js'),
            },
        },
    };
});
