import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// The SPA mirrors the Symfony session, so session/login/logout calls must reach
// the API from the same origin in development; otherwise the browser would not
// send or receive the session cookie.
const backendOrigin = process.env.KEELSON_BACKEND_ORIGIN ?? 'http://127.0.0.1:8000'
const proxy = { target: backendOrigin, changeOrigin: false }

export default defineConfig({
  plugins: [react()],
  server: {
    proxy: {
      '/api': proxy,
      '/login': proxy,
      '/logout': proxy,
    },
  },
})
