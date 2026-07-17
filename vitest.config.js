import { fileURLToPath, URL } from 'node:url'
import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vitest/config'

export default defineConfig({
    plugins: [
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
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        environment: 'jsdom',
        environmentOptions: {
            jsdom: {
                url: 'http://localhost',
            },
        },
        setupFiles: ['./tests/js/setup.js'],
        include: ['tests/js/**/*.spec.js'],
        clearMocks: true,
        restoreMocks: true,
    },
})
