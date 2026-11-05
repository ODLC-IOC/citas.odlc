import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
// Código fuente de la app (React). Se compila en la raíz del repositorio (index.html + app/),
// que es lo que sirve citas.odlc.es. No borra la API PHP (api/).
export default defineConfig({
  plugins: [react()],
  base: '/',
  build: { outDir: '..', emptyOutDir: false, assetsDir: 'app' },
  server: { proxy: { '/api': 'http://localhost:8900', '/r/': 'http://localhost:8900' } },
});
