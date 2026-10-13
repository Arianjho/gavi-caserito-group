import { errorMessages } from "@/i18n/es-PE";

/**
 * Forma del cuerpo de error que devuelve la API:
 * `{ code, message, fields?, request_id? }`.
 */
export interface ApiErrorBody {
  /** Código estable en inglés (por ejemplo `stock_insufficient`). */
  code?: string;
  /** Mensaje ya traducido por el servidor; se usa si no hay texto local. */
  message?: string;
  /** Errores por campo: nombre del campo → mensajes. */
  fields?: Record<string, string[]> | undefined;
  /** Identificador de la solicitud, útil para soporte. */
  request_id?: string | undefined;
}

/**
 * Error de comunicación o de negocio devuelto por la API.
 *
 * Distingue errores que el usuario puede corregir (`kind = "user"`) de fallas
 * del sistema o de la red (`kind = "system"`), para elegir el aviso adecuado.
 */
export class ApiError extends Error {
  /** Estado HTTP; `0` cuando no hubo respuesta (red caída o tiempo agotado). */
  readonly status: number;
  /** Código estable de error, si el servidor lo envió. */
  readonly code: string | undefined;
  /** Errores por campo para marcar el formulario. */
  readonly fields: Record<string, string[]>;
  /** Identificador de solicitud para soporte. */
  readonly requestId: string | undefined;

  /**
   * Crea un error de API.
   *
   * @param status Estado HTTP (0 si no hubo respuesta).
   * @param body Cuerpo de error ya interpretado.
   */
  constructor(status: number, body: ApiErrorBody = {}) {
    super(body.message ?? "Error de la API");
    this.name = "ApiError";
    this.status = status;
    this.code = body.code;
    this.fields = body.fields ?? {};
    this.requestId = body.request_id;
  }

  /**
   * Clasificación del error: `user` si el usuario puede corregirlo
   * (validación, regla de negocio, permiso) y `system` si es una falla técnica.
   */
  get kind(): "user" | "system" {
    return this.status >= 400 && this.status < 500 && this.status !== 408 && this.status !== 429
      ? "user"
      : "system";
  }

  /**
   * Texto amigable para mostrar al usuario: primero el diccionario local por
   * código, luego el mensaje del servidor y por último un texto genérico.
   */
  get friendlyMessage(): string {
    if (this.code && errorMessages[this.code]) return errorMessages[this.code]!;
    if (this.kind === "user" && this.message) return this.message;
    return errorMessages["system_error"]!;
  }
}

/**
 * Indica si un valor desconocido es un {@link ApiError}.
 *
 * @param value Valor a comprobar.
 */
export function isApiError(value: unknown): value is ApiError {
  return value instanceof ApiError;
}
