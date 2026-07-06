import { defineConfig } from 'vite';

export default defineConfig({
  publicDir: false,
  build: {
    manifest: true,
    outDir: 'public/build',
    rollupOptions: {
      input: {
        app: 'assets/ts/app.ts',
        styles: 'assets/css/app.css'
      }
    }
  },
  server: {
    strictPort: false,
    port: 5173
  }
});
