import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// Dev proxy target. Overridable for environments where the backend isn't on
// this process's localhost — e.g. Vite running in a container on a Docker-only
// host (start.sh sets TYDAL_BACKEND_URL=http://host.docker.internal:8000).
// Same idiom as vaults/gallery/vite.config.ts.
const BACKEND = process.env.TYDAL_BACKEND_URL ?? 'http://localhost:8000'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  server: {
    host: true, // Expose to network
    port: 3005,
    open: false, // Disable auto-open to prevent xdg-open errors
    // The frontend is the public entry point (as the CDN was): it proxies the
    // public vault surfaces to the backend so internal storage is never exposed
    // directly. Set a vault's base_url to this frontend host to route through it.
    proxy: {
      '/v': { target: BACKEND, changeOrigin: true },
      '/vault': { target: BACKEND, changeOrigin: true },
      '/cdn': { target: BACKEND, changeOrigin: true },
    },
  },
  build: {
    outDir: 'build',
    sourcemap: true,
  },
})
