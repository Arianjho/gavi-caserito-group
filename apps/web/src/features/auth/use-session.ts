import { useQuery } from "@tanstack/react-query";

import { api, getToken } from "@/lib/api";

/** Usuario autenticado. */
export interface SessionUser {
  id: string;
  tenant_id: string;
  name: string;
  email: string;
  is_owner: boolean;
}

/** Respuesta de `GET /auth/me`. */
export interface SessionData {
  user: SessionUser;
  /** Códigos de permiso efectivos del usuario. */
  permissions: string[];
}

/** Clave de consulta de la sesión. */
export const sessionQueryKey = ["session"] as const;

/**
 * Carga el usuario autenticado y sus permisos.
 *
 * Solo consulta si hay token guardado.
 *
 * @returns Estado de la consulta con los datos de sesión.
 */
export function useSession() {
  return useQuery({
    queryKey: sessionQueryKey,
    queryFn: () => api<SessionData>("/auth/me"),
    enabled: getToken() !== null,
    staleTime: 5 * 60_000,
  });
}

/**
 * Indica si el usuario tiene un permiso.
 *
 * Los ítems sin permiso declarado son visibles para todos.
 *
 * @param permissions Permisos efectivos del usuario.
 * @param code Código requerido, si lo hay.
 */
export function hasPermission(permissions: string[] | undefined, code?: string): boolean {
  if (!code) return true;
  return permissions?.includes(code) ?? false;
}
