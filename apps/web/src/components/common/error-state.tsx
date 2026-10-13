import { TriangleAlert } from "lucide-react";

import { Button } from "@/components/ui/button";
import { t } from "@/i18n/es-PE";

/** Propiedades de {@link ErrorState}. */
export interface ErrorStateProps {
  /** Título del error; por defecto un texto genérico. */
  title?: string;
  /** Explicación y siguiente paso para el usuario. */
  description?: string;
  /** Acción de reintento; si se omite no se muestra el botón. */
  onRetry?: () => void;
}

/**
 * Estado de error de un bloque de datos.
 *
 * Se muestra dentro del bloque que falló, sin vaciar el resto de la pantalla,
 * y ofrece **Reintentar**.
 */
export function ErrorState({
  title = t("common.error.title"),
  description = t("common.error.description"),
  onRetry,
}: ErrorStateProps) {
  return (
    <div
      role="alert"
      className="flex flex-col items-center justify-center gap-2 rounded-xl border border-destructive/30 bg-destructive/5 px-6 py-10 text-center"
    >
      <span className="grid size-11 place-items-center rounded-full bg-destructive/10 text-destructive">
        <TriangleAlert className="size-5" aria-hidden="true" />
      </span>
      <h3 className="text-base font-semibold">{title}</h3>
      <p className="max-w-sm text-sm text-muted-foreground">{description}</p>
      {onRetry && (
        <Button variant="outline" size="sm" className="mt-2" onClick={onRetry}>
          {t("common.retry")}
        </Button>
      )}
    </div>
  );
}
