import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import path from 'node:path'

// https://vite.dev/config/
const config = defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  server: {
    // Dev-only proxies — production uses Nginx
    proxy: {
      '/api': {
        target: process.env.API_PROXY_TARGET ?? 'http://127.0.0.1:8080',
        changeOrigin: true,
      },
      // Exported PDF/PNG files live under the backend's public/exports.
      '/exports': {
        target: process.env.API_PROXY_TARGET ?? 'http://127.0.0.1:8080',
        changeOrigin: true,
      },
      '/.well-known/mercure': {
        target: process.env.MERCURE_PROXY_TARGET ?? 'http://127.0.0.1:3000',
        changeOrigin: true,
      },
      // FRT-17: no `/engine` proxy — the frontend NEVER calls the engine directly
      // (boundary §2); it goes through the backend `/api`. The dead proxy is removed.
    },
  },
})

export default config
