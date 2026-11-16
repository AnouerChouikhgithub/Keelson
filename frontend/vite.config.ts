import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// Every backend call the SPA makes lives under `/api`, and the browser must reach
// it from the SPA's own origin so the session cookie is sent and stored. Nothing
// else is proxied: the public screens are client-side routes, so `/sign-in` and
// friends are served by the SPA itself.
const backendOrigin = process.env.KEELSON_BACKEND_ORIGIN ?? 'http://127.0.0.1:8000'

export default defineConfig({
  plugins: [react()],
  server: {
    proxy: {
      '/api': { target: backendOrigin, changeOrigin: false },
    },
  },
})
