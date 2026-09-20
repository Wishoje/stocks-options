import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath } from 'node:url'

export default defineConfig({
  root: fileURLToPath(new URL('./ui-preview', import.meta.url)),
  plugins: [vue()],
  css: { postcss: { plugins: [] } },
  server: { host: '127.0.0.1', port: 4173, strictPort: true },
  preview: { host: '127.0.0.1', port: 4173, strictPort: true },
  build: { outDir: '../storage/app/ui-preview', emptyOutDir: true },
})
