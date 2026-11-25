import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    // LAN IP of this machine, so teammates on the same Wi-Fi can load dev assets.
    const devHost = env.VITE_DEV_HOST;

    return {
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/main.jsx'],
                refresh: true,
            }),
            react(),
            tailwindcss(),
        ],
        server: {
            // Listen on the network only when sharing; otherwise localhost (browsers block 0.0.0.0 URLs).
            host: devHost ? '0.0.0.0' : '127.0.0.1',
            port: 5174,
            strictPort: true,
            cors: true,
            origin: devHost ? `http://${devHost}:5174` : undefined,
            hmr: devHost ? { host: devHost } : undefined,
            watch: {
                ignored: ['**/storage/framework/views/**'],
            },
        },
    };
});
