import { defineConfig } from 'vitest/config'
import react from '@vitejs/plugin-react'

// Dev proxy: the app talks same-origin to the vault grammar; vite fronts the
// backend exactly like the production web root would.
const BACKEND = process.env.TYDAL_BACKEND_URL ?? 'http://localhost:8000'

export default defineConfig({
  plugins: [react()],
  server: {
    port: 3010,
    proxy: {
      '/v': { target: BACKEND, changeOrigin: true },
      '/h': { target: BACKEND, changeOrigin: true },
      '/vault': { target: BACKEND, changeOrigin: true },
    },
  },
  test: {
    environment: 'node',
    include: ['src/**/*.test.ts'],
  },
})
