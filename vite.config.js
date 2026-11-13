import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/css/filament/admin/theme.css'],
            refresh: true,
            fonts: [
                bunny('Geist', {
                    weights: [400, 500, 600],
                    optimizedFallbacks: false,
                }),
                bunny('Geist Mono', {
                    weights: [400, 500],
                    optimizedFallbacks: false,
                }),
                bunny('Barlow Condensed', {
                    weights: [600],
                    optimizedFallbacks: false,
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
