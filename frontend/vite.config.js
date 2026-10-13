import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { defineConfig } from 'vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: {
    host: true,
    watch: { usePolling: true },
    // changeOrigin: false conserva el host del navegador (p. ej. unsa.localhost:5173): el backend identifica la
    // institución por el subdominio (PLATAFORMA_MODO=subdominio).
    proxy: {
      '/api': { target: 'http://nginx', changeOrigin: false },
      '/sanctum': { target: 'http://nginx', changeOrigin: false },
    },
  },
})
