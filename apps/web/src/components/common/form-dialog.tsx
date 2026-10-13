import type { FormEvent, ReactNode } from "react";

import { ActionButton } from "@/components/common/action-button";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { t } from "@/i18n/es-PE";

/** Propiedades de {@link FormDialog}. */
export interface FormDialogProps {
  /** Si el diálogo está abierto. */
  open: boolean;
  /** Cambia la visibilidad; se ignora mientras hay un proceso en curso. */
  onOpenChange: (open: boolean) => void;
  /** Título del diálogo. */
  title: string;
  /** Descripción breve opcional. */
  description?: string | undefined;
  /** Campos del formulario. */
  children: ReactNode;
  /** Se invoca al enviar el formulario. */
  onSubmit: () => void;
  /** Hay una petición en curso: se bloquean campos, botones y cierre. */
  processing?: boolean;
  /** La petición lleva más tiempo del normal: se muestra un aviso. */
  slow?: boolean;
  /** Texto del botón principal (por ejemplo "Registrar cliente"). */
  submitLabel?: string;
  /** Texto del botón principal durante el proceso (por ejemplo "Registrando…"). */
  processingLabel?: string;
  /** Texto del botón secundario. */
  cancelLabel?: string;
  /** Ancho máximo del diálogo. */
  size?: "sm" | "md" | "lg";
}

const widths: Record<NonNullable<FormDialogProps["size"]>, string> = {
  sm: "sm:max-w-sm",
  md: "sm:max-w-lg",
  lg: "sm:max-w-2xl",
};

/**
 * Diálogo de formulario con **bloqueo de contexto**.
 *
 * Mientras `processing` es verdadero:
 * - los campos quedan deshabilitados (`fieldset disabled`);
 * - el botón principal muestra el verbo en curso y no admite otro clic;
 * - no se puede cerrar (botón X, Esc, clic fuera ni Cancelar).
 *
 * Al terminar, el componente que lo usa decide si lo cierra (éxito) o lo deja
 * abierto con los errores marcados (error corregible).
 */
export function FormDialog({
  open,
  onOpenChange,
  title,
  description,
  children,
  onSubmit,
  processing = false,
  slow = false,
  submitLabel = t("common.save"),
  processingLabel = t("common.saving"),
  cancelLabel = t("common.cancel"),
  size = "md",
}: FormDialogProps) {
  const handleOpenChange = (next: boolean) => {
    if (processing && !next) return;
    onOpenChange(next);
  };

  const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!processing) onSubmit();
  };

  const block = (event: Event) => {
    if (processing) event.preventDefault();
  };

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent
        className={widths[size]}
        hideClose={processing}
        onEscapeKeyDown={block}
        onPointerDownOutside={block}
        onInteractOutside={block}
      >
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          {description && <DialogDescription>{description}</DialogDescription>}
        </DialogHeader>

        <form onSubmit={handleSubmit} className="grid gap-4" noValidate>
          {processing && (
            <p
              role="status"
              className="rounded-md bg-info/10 px-3 py-2 text-sm text-info"
              aria-live="polite"
            >
              {slow ? t("common.processing.slow") : t("common.processing.hint")}
            </p>
          )}

          <fieldset disabled={processing} className="grid min-w-0 gap-4 border-0 p-0">
            {children}
          </fieldset>

          <DialogFooter className="gap-2 sm:gap-0">
            <Button
              type="button"
              variant="outline"
              disabled={processing}
              onClick={() => onOpenChange(false)}
            >
              {cancelLabel}
            </Button>
            <ActionButton type="submit" processing={processing} processingLabel={processingLabel}>
              {submitLabel}
            </ActionButton>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
