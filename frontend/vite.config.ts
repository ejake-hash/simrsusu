/// <reference types="vitest/config" />
import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// Backend tujuan proksi; bisa diganti (mis. worktree lain di port 8001) lewat SIMRS_API_URL.
const api = process.env.SIMRS_API_URL ?? 'http://127.0.0.1:8000'

// https://vite.dev/config/
export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
  },
  server: {
    // /sanctum ikut diproksi agar cookie CSRF dan sesi satu origin dengan SPA (SameSite=Strict).
    proxy: {
      '/api': api,
      '/sanctum': api,
    },
  },
  test: {
    environment: 'jsdom',
    include: ['src/**/*.spec.ts'],
  },
})
