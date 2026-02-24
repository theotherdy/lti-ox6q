import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

export default defineConfig({
  plugins: [react()],
  // VITE_BASE_PATH is injected at build time for subdirectory deployments.
  // Defaults to '/' so local dev is unaffected.
  base: process.env.VITE_BASE_PATH || '/',
  build: {
    rollupOptions: {
      input: {
        main: 'index.html',
        structuredRunner: 'structured-runner.html',
      },
    },
  },
  server: {
    host: true,
    port: 5173,
    strictPort: true,
    cors: true,
  }
})
