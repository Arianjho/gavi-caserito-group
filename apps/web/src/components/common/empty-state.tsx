import type { LucideIcon } from "lucide-react";
import { Inbox } from "lucide-react";
import type { ReactNode } from "react";

import { t } from "@/i18n/es-PE";

/** Propiedades de {@link EmptyState}. */
export interface EmptyStateProps {
  /** Título breve; por defecto un texto genérico. */
  title?: string;
  /** Explicación de qué es esta lista y cómo empezar. */
  description?: string;
  /** Icono ilustrativo. */
  icon?: LucideIcon;
  /** Acción principal (por ejemplo un botón "Crear el primero"). */
  action?: ReactNode;
}

/**
 * Estado vacío de listas y paneles.
 *
 * Explica qué se verá aquí y ofrece la acción principal para empezar.
 */
export function EmptyState({
  title = t("common.empty.title"),
  description,
  icon: Icon = Inbox,
  action,
}: EmptyStateProps) {
  return (
    <div className="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-border px-6 py-12 text-center">
      <span className="grid size-11 place-items-center rounded-full bg-muted text-muted-foreground">
        <Icon className="size-5" aria-hidden="true" />
      </span>
      <h3 className="text-base font-semibold">{title}</h3>
      {description && <p className="max-w-sm text-sm text-muted-foreground">{description}</p>}
      {action && <div className="mt-2">{action}</div>}
    </div>
  );
}
