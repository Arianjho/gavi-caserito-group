import { useCallback, useEffect, useRef, useState } from "react";
import { toast } from "sonner";

import { ApiError, isApiError } from "@/lib/api-error";
import { errorMessages, t } from "@/i18n/es-PE";

/** Estados posibles de una operación protegida. */
export type GuardedStatus = "idle" | "processing" | "error" | "timeout" | "success";

/** Contexto que recibe la función que ejecuta la operación. */
export interface GuardedContext {
  /** Llave de idempotencia: se reutiliza en los reintentos hasta tener un resultado definitivo. */
  idempotencyKey: string;
  /** Señal para cancelar la petición cuando se agota el tiempo máximo. */
  signal: AbortSignal;
}

/** Opciones de {@link useGuardedMutation}. */
export interface GuardedMutationOptions<TInput, TResult> {
  /** Ejecuta la operación contra la API. */
  mutationFn: (input: TInput, context: GuardedContext) => Promise<TResult>;
  /** Mensaje de éxito para el aviso; si se omite no se muestra aviso de éxito. */
  successMessage?: string | ((result: TResult) => string);
  /** Se invoca una vez que la operación termina bien. */
  onSuccess?: (result: TResult) => void;
  /** Aviso a los N ms de que "sigue procesando" (por defecto 15 000). */
  slowAfterMs?: number;
  /** Tiempo máximo antes de cortar la petición (por defecto 30 000). */
  timeoutMs?: number;
}

/** Resultado de {@link useGuardedMutation}. */
export interface GuardedMutation<TInput> {
  /** Ejecuta la operación con bloqueo de contexto. */
  run: (input: TInput) => Promise<void>;
  /** Estado actual. */
  status: GuardedStatus;
  /** `true` mientras la petición está en curso (bloquea formulario y cierre). */
  isProcessing: boolean;
  /** `true` cuando la operación supera el aviso de lentitud. */
  isSlow: boolean;
  /** Errores por campo devueltos por la API, para marcar el formulario. */
  fieldErrors: Record<string, string[]>;
  /** Vuelve al estado inicial (por ejemplo al reabrir un diálogo). */
  reset: () => void;
}

/**
 * Crea una llave de idempotencia única.
 */
function newIdempotencyKey(): string {
  return typeof crypto !== "undefined" && "randomUUID" in crypto
    ? crypto.randomUUID()
    : `${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

/**
 * Ejecuta una operación contra la API aplicando el contrato de interacción:
 *
 * 1. Bloquea el contexto mientras se ejecuta (`isProcessing`).
 * 2. Avisa si tarda más de lo normal y corta al tiempo máximo.
 * 3. Reutiliza la llave de idempotencia en los reintentos para no duplicar registros.
 * 4. Traduce los errores a avisos amigables: advertencia si el usuario puede
 *    corregirlo, peligro si es una falla del sistema.
 * 5. Avisa antes de abandonar la página mientras hay un proceso en curso.
 *
 * @param options Función de operación y mensajes.
 * @returns Estado y acción `run` para el formulario o botón.
 */
export function useGuardedMutation<TInput, TResult = unknown>(
  options: GuardedMutationOptions<TInput, TResult>,
): GuardedMutation<TInput> {
  const { slowAfterMs = 15_000, timeoutMs = 30_000 } = options;
  const [status, setStatus] = useState<GuardedStatus>("idle");
  const [isSlow, setIsSlow] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const keyRef = useRef<string>(newIdempotencyKey());
  const optionsRef = useRef(options);
  optionsRef.current = options;

  const isProcessing = status === "processing";

  useEffect(() => {
    if (!isProcessing) return;
    const guard = (event: BeforeUnloadEvent) => {
      event.preventDefault();
    };
    window.addEventListener("beforeunload", guard);
    return () => window.removeEventListener("beforeunload", guard);
  }, [isProcessing]);

  const reset = useCallback(() => {
    setStatus("idle");
    setIsSlow(false);
    setFieldErrors({});
  }, []);

  const run = useCallback(
    async (input: TInput) => {
      const controller = new AbortController();
      setStatus("processing");
      setIsSlow(false);
      setFieldErrors({});

      const slowTimer = window.setTimeout(() => setIsSlow(true), slowAfterMs);
      let timedOut = false;
      const hardTimer = window.setTimeout(() => {
        timedOut = true;
        controller.abort();
      }, timeoutMs);

      try {
        const result = await optionsRef.current.mutationFn(input, {
          idempotencyKey: keyRef.current,
          signal: controller.signal,
        });
        keyRef.current = newIdempotencyKey();
        setStatus("success");
        const message = optionsRef.current.successMessage;
        if (message) {
          toast.success(typeof message === "function" ? message(result) : message);
        }
        optionsRef.current.onSuccess?.(result);
      } catch (error) {
        if (timedOut) {
          setStatus("timeout");
          toast.error(t("common.processing.timeout"), {
            description: "Verificaremos si se guardó antes de permitirte reintentar.",
          });
          return;
        }
        const apiError = isApiError(error)
          ? error
          : new ApiError(0, { code: "network_unreachable" });
        if (apiError.kind === "user") {
          // Resultado definitivo: la próxima solicitud es otro intento nuevo.
          keyRef.current = newIdempotencyKey();
          setFieldErrors(apiError.fields);
          setStatus("error");
          toast.warning(apiError.friendlyMessage);
        } else {
          setStatus("error");
          toast.error(apiError.friendlyMessage, {
            description: apiError.requestId
              ? `Código de soporte: ${apiError.requestId}`
              : undefined,
          });
        }
      } finally {
        window.clearTimeout(slowTimer);
        window.clearTimeout(hardTimer);
        setIsSlow(false);
      }
    },
    [slowAfterMs, timeoutMs],
  );

  return { run, status, isProcessing, isSlow, fieldErrors, reset };
}

/** Texto de un código de error conocido (útil en pruebas y documentación). */
export const knownErrorCodes = Object.keys(errorMessages);
