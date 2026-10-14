import tailwindcss from "@tailwindcss/vite";
import { tanstackRouter } from "@tanstack/router-plugin/vite";
import react from "@vitejs/plugin-react";
import path from "node:path";
import { defineConfig } from "vite";

/**
 * Configuración de Vite para la SPA de Gavi Caserito.
 *
 * - Rutas basadas en archivos (TanStack Router) con división de código automática.
 * - Tailwind CSS 4 mediante su plugin de Vite.
 * - Proxy de desarrollo hacia la API Laravel (`/api` → http://127.0.0.1:8000, o `VITE_API_PROXY`).
 */
export default defineConfig({
  plugins: [tanstackRouter({ target: "react", autoCodeSplitting: true }), react(), tailwindcss()],
  resolve: {
    alias: { "@": path.resolve(__dirname, "src") },
  },
  server: {
    port: 5173,
    // En Docker, la API es otro contenedor y los archivos montados necesitan sondeo.
    watch: { usePolling: process.env["VITE_POLLING"] === "true" },
    proxy: {
      "/api": {
        target: process.env["VITE_API_PROXY"] ?? "http://127.0.0.1:8000",
        changeOrigin: true,
      },
    },
  },
});
