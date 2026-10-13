import { QueryClient } from "@tanstack/react-query";
import { createRouter } from "@tanstack/react-router";

import { routeTree } from "./routeTree.gen";

/**
 * Cliente de consultas compartido por toda la aplicación.
 *
 * Los datos del servidor se tratan como frescos 30 s para evitar peticiones
 * repetidas al navegar; las pantallas críticas pueden pedir datos nuevos.
 */
export const queryClient = new QueryClient({
  defaultOptions: {
    queries: { staleTime: 30_000, retry: 1, refetchOnWindowFocus: false },
  },
});

/** Enrutador de la aplicación (rutas basadas en archivos). */
export const router = createRouter({
  routeTree,
  context: { queryClient },
  scrollRestoration: true,
  defaultPreloadStaleTime: 0,
});

declare module "@tanstack/react-router" {
  interface Register {
    router: typeof router;
  }
}
