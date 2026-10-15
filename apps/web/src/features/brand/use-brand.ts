import { useQuery } from "@tanstack/react-query";

import { api, getToken } from "@/lib/api";
import { DEFAULT_BRAND, type Brand } from "@/lib/brand";

/**
 * Marca vigente de la cuenta.
 *
 * Con sesión carga la de la cuenta del usuario; sin sesión (login, portal de
 * seguimiento) la resuelve por el dominio con el que se abrió la aplicación.
 *
 * @returns Marca; la del producto mientras carga o si no hay personalización.
 */
export function useBrand(): Brand {
  const signedIn = getToken() !== null;
  const host = typeof window === "undefined" ? "" : window.location.hostname;
  const query = useQuery({
    queryKey: ["brand", signedIn ? "mine" : host],
    queryFn: async () =>
      (
        await api<{ data: Brand }>(
          signedIn ? "/brand" : `/public/brand?domain=${encodeURIComponent(host)}`,
        )
      ).data,
    staleTime: 5 * 60_000,
  });
  return query.data ?? DEFAULT_BRAND;
}
