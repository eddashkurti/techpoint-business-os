import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';
import { fileURLToPath, URL } from 'node:url';
export default defineConfig({
    resolve: { alias: { '@': fileURLToPath(new URL('./resources/js', import.meta.url)) } },
    plugins: [
        laravel({ input: ['resources/css/app.css', 'resources/js/app.ts'], refresh: true }),
        inertia(), tailwindcss(), vue({template:{transformAssetUrls:{base:null,includeAbsolute:false}}}),
        ...(process.env.WAYFINDER_SKIP === '1' ? [] : [wayfinder({formVariants:true})]),
    ],
});
