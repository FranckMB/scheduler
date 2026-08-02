import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import fs from 'node:fs'
import path from 'node:path'

import { CSP_PATH, sentryCspError } from './build/sentryCspGuard'

// P4-65 — activer Sentry demande DEUX gestes, pas un : poser `VITE_SENTRY_DSN` ET autoriser
// son hôte d'ingestion dans la CSP. Oublier le second ne casse rien de visible — le SDK
// s'initialise, et le navigateur jette chaque envoi. Ce garde échoue le BUILD plutôt que de
// laisser découvrir le trou en cherchant une erreur de production. Inerte sans DSN.
const cspFile = path.resolve(__dirname, CSP_PATH)
const sentryProbleme = sentryCspError(
  process.env.VITE_SENTRY_DSN,
  fs.existsSync(cspFile) ? fs.readFileSync(cspFile, 'utf8') : null,
)
if (null !== sentryProbleme) {
  throw new Error(`[P4-65] ${sentryProbleme}`)
}

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
