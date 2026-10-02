import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { defineConfig } from 'vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: {
    host: true,
    watch: { usePolling: true },
    proxy: {
      '/api': { target: 'http://nginx', changeOrigin: true },
      '/sanctum': { target: 'http://nginx', changeOrigin: true },
    },
  },
})
