import { ApiError, type ApiErrorBody } from "@/lib/api-error";

const BASE_URL = "/api/v1";
const TOKEN_KEY = "caserito-token";

/**
 * Lee el token de sesión guardado en el navegador.
 *
 * @returns Token o `null` si no hay sesión.
 */
export function getToken(): string | null {
  try {
    return localStorage.getItem(TOKEN_KEY);
  } catch {
    return null;
  }
}

/**
 * Guarda o elimina el token de sesión.
 *
 * @param token Token nuevo, o `null` para cerrar la sesión local.
 */
export function setToken(token: string | null): void {
  try {
    if (token) localStorage.setItem(TOKEN_KEY, token);
    else localStorage.removeItem(TOKEN_KEY);
  } catch {
    /* el almacenamiento puede no estar disponible */
  }
}

/** Opciones de una petición a la API. */
export interface RequestOptions {
  /** Método HTTP; por defecto `GET`. */
  method?: "GET" | "POST" | "PUT" | "PATCH" | "DELETE";
  /** Cuerpo JSON. */
  body?: unknown;
  /** Llave de idempotencia para operaciones que crean registros. */
  idempotencyKey?: string;
  /** Señal para cancelar la petición. */
  signal?: AbortSignal;
}

/**
 * Realiza una petición JSON a la API v1.
 *
 * Traduce cualquier falla a {@link ApiError}: errores de red con estado 0 y
 * respuestas no exitosas con su código y errores por campo.
 * Una respuesta 401 con sesión guardada la cierra y envía a iniciar sesión.
 *
 * @param path Ruta relativa a `/api/v1` (por ejemplo `/auth/me`).
 * @param options Método, cuerpo y control de la petición.
 * @returns Cuerpo JSON de la respuesta.
 * @throws {ApiError} Si la petición falla.
 */
export async function api<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const headers: Record<string, string> = { Accept: "application/json" };
  const token = getToken();
  if (token) headers["Authorization"] = `Bearer ${token}`;
  if (options.body !== undefined) headers["Content-Type"] = "application/json";
  if (options.idempotencyKey) headers["Idempotency-Key"] = options.idempotencyKey;

  let response: Response;
  try {
    const init: RequestInit = { method: options.method ?? "GET", headers };
    if (options.body !== undefined) init.body = JSON.stringify(options.body);
    if (options.signal) init.signal = options.signal;
    response = await fetch(`${BASE_URL}${path}`, init);
  } catch (error) {
    if (error instanceof DOMException && error.name === "AbortError") throw error;
    throw new ApiError(0, { code: "network_unreachable" });
  }

  if (response.status === 401 && token) {
    setToken(null);
    window.location.assign("/login");
  }

  const text = await response.text();
  const json: unknown = text ? JSON.parse(text) : null;

  if (!response.ok) {
    const body = (json ?? {}) as ApiErrorBody & { errors?: Record<string, string[]> };
    throw new ApiError(response.status, {
      ...body,
      fields: body.fields ?? body.errors,
      request_id: body.request_id ?? response.headers.get("X-Request-Id") ?? undefined,
    });
  }

  return json as T;
}
