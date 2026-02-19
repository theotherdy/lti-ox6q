import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

export default defineConfig({
  plugins: [react()],
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
