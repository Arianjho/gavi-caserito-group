import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { api } from "@/lib/api";

/** Estado de vida de un maestro. */
export type LifecycleStatus = "active" | "inactive" | "deleted";

/** Empresa (una por RUC). */
export interface Company {
  id: string;
  status: LifecycleStatus;
  legal_name: string;
  trade_name: string | null;
  tax_id: string;
  address: string | null;
  fiscal_mode: "none" | "optional" | "required";
}

/** Sucursal de una empresa. */
export interface Branch {
  id: string;
  status: LifecycleStatus;
  company_id: string;
  code: string;
  name: string;
  address: string | null;
  is_main: boolean;
}

/** Almacén de una sucursal. */
export interface Warehouse {
  id: string;
  status: LifecycleStatus;
  company_id: string;
  branch_id: string;
  name: string;
  kind: "sales" | "storage" | "transit";
}

/**
 * Lista un maestro de la API.
 *
 * @param path Ruta del recurso (por ejemplo `companies`).
 * @returns Consulta con la lista de registros no eliminados.
 */
export function useResourceList<T>(path: string) {
  return useQuery({
    queryKey: ["resource", path],
    queryFn: async () => (await api<{ data: T[] }>(`/${path}`)).data,
  });
}

/**
 * Devuelve una función que invalida la lista de un maestro tras un cambio.
 *
 * @param path Ruta del recurso.
 */
export function useInvalidateResource(path: string) {
  const queryClient = useQueryClient();
  return () => queryClient.invalidateQueries({ queryKey: ["resource", path] });
}

/**
 * Mutación simple para activar o desactivar un registro.
 *
 * @param path Ruta del recurso.
 * @returns Mutación de TanStack Query con `{ id, active }`.
 */
export function useSetActive(path: string) {
  const invalidate = useInvalidateResource(path);
  return useMutation({
    mutationFn: (input: { id: string; active: boolean }) =>
      api(`/${path}/${input.id}/active`, { method: "PATCH", body: { active: input.active } }),
    onSuccess: () => invalidate(),
  });
}
