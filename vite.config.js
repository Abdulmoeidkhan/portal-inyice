import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const appUrl = env.VITE_APP_URL || env.APP_URL || 'http://127.0.0.1:8000';

    return {
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.jsx'],
                refresh: true,
            }),
            react(),
        ],
        server: {
            port: Number(env.VITE_PORT || 5174),
            proxy: {
                '/api': {
                    target: appUrl,
                    changeOrigin: true,
                    secure: false,
                },
            },
            watch: {
                ignored: ['**/storage/framework/views/**'],
            },
        },
    };
});
