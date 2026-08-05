import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [vue(), tailwindcss()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  // Alcance deliberadamente mínimo (ver 011-precio-proveedor-utilidad.md): solo el módulo de
  // cálculo de precios, que es aritmética pura y no necesita jsdom ni montar componentes.
  test: {
    include: ['src/lib/**/*.test.ts'],
    environment: 'node',
  },
})
