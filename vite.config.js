import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.jsx'],
            refresh: true,
        }),
        react(),
    ],
    server: {
        port: Number(process.env.VITE_PORT || 5174),
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
