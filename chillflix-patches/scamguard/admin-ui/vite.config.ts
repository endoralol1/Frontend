import path from 'path'
import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig({
  plugins: [react(), tailwindcss()],
  base: '/scamguard/admin/app/',
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  build: {
    outDir: path.resolve(__dirname, '../admin/app'),
    emptyOutDir: true,
  },
  server: {
    proxy: {
      '/scamguard/admin/api.php': {
        target: 'https://www.chillflix.lol',
        changeOrigin: true,
        secure: false,
      },
    },
  },
})
