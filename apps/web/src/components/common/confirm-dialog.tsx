import type { ReactNode } from "react";

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

/** Propiedades de {@link ConfirmDialog}. */
export interface ConfirmDialogProps {
  /** Si el diálogo está abierto. */
  open: boolean;
  /** Cambia la visibilidad; se ignora mientras hay un proceso en curso. */
  onOpenChange: (open: boolean) => void;
  /** Título de la confirmación. */
  title: string;
  /** Explicación de lo que ocurrirá; debe nombrar el elemento afectado. */
  description: ReactNode;
  /** Se invoca al confirmar. */
  onConfirm: () => void;
  /** Hay una petición en curso: se bloquea el diálogo. */
  processing?: boolean;
  /** Texto del botón de confirmación (por ejemplo "Eliminar"). */
  confirmLabel?: string;
  /** Texto del botón de confirmación durante el proceso. */
  processingLabel?: string;
  /** Texto del botón de cancelar. */
  cancelLabel?: string;
  /** Estilo destructivo (acción irreversible o con efecto grande). */
  destructive?: boolean;
}

/**
 * Diálogo de confirmación con el mismo bloqueo que {@link FormDialog}.
 *
 * Debe usarse para acciones destructivas y siempre nombrar el elemento afectado
 * en la descripción.
 */
export function ConfirmDialog({
  open,
  onOpenChange,
  title,
  description,
  onConfirm,
  processing = false,
  confirmLabel = t("common.delete"),
  processingLabel = t("common.deleting"),
  cancelLabel = t("common.cancel"),
  destructive = true,
}: ConfirmDialogProps) {
  const block = (event: Event) => {
    if (processing) event.preventDefault();
  };

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (processing && !next) return;
        onOpenChange(next);
      }}
    >
      <DialogContent
        className="sm:max-w-md"
        hideClose={processing}
        onEscapeKeyDown={block}
        onPointerDownOutside={block}
        onInteractOutside={block}
      >
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{description}</DialogDescription>
        </DialogHeader>
        <DialogFooter className="gap-2 sm:gap-0">
          <Button variant="outline" disabled={processing} onClick={() => onOpenChange(false)}>
            {cancelLabel}
          </Button>
          <ActionButton
            variant={destructive ? "destructive" : "default"}
            processing={processing}
            processingLabel={processingLabel}
            onClick={onConfirm}
          >
            {confirmLabel}
          </ActionButton>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
