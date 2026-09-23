import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

/*
 * RULE #4 — no external CDN, and no build-time font fetching.
 *
 * The Laravel skeleton ships `bunny('Instrument Sans')` from
 * laravel-vite-plugin/fonts. That plugin downloads the font at build time and
 * serves it locally, so it is not a *runtime* CDN call — but it is still a
 * build-time network dependency on fonts.bunny.net, and it bundles a Latin-only
 * typeface this panel never renders. Both are removed.
 *
 * Vazirmatn is vendored into public/fonts/vazirmatn/ and declared with
 * @font-face in public/css/vazirmatn.css, so the panel needs no font plugin at
 * all and the build works with no network access.
 */
export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/css/filament/admin/theme.css',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
