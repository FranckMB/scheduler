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
        target: 'http://127.0.0.1:8080',
        changeOrigin: true,
      },
      '/.well-known/mercure': {
        target: 'http://127.0.0.1:3000',
        changeOrigin: true,
      },
      '/engine': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
    },
  },
})

export default config
