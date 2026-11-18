import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/bpjs-dashboard.js', 'resources/js/bpjs-histori.js', 'resources/js/manual-upload.js', 'resources/js/folder-status.js', 'resources/js/pnpk-review.js', 'resources/js/folder-page.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
});
