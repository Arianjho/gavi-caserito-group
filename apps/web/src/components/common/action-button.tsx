import { Loader2 } from "lucide-react";
import type { ComponentProps } from "react";

import { Button } from "@/components/ui/button";

/** Propiedades de {@link ActionButton}. */
export interface ActionButtonProps extends ComponentProps<typeof Button> {
  /** Indica que la acción está en curso: el botón se bloquea y muestra `processingLabel`. */
  processing?: boolean;
  /** Texto con verbo en gerundio que se muestra mientras corre ("Registrando…"). */
  processingLabel?: string;
}

/**
 * Botón de acción con estados de proceso.
 *
 * Evita el doble clic: mientras `processing` es verdadero queda deshabilitado
 * y cambia su texto por el verbo en curso, con un indicador de carga.
 */
export function ActionButton({
  processing = false,
  processingLabel,
  children,
  disabled,
  ...props
}: ActionButtonProps) {
  return (
    <Button disabled={disabled || processing} aria-busy={processing} {...props}>
      {processing && <Loader2 className="animate-spin" aria-hidden="true" />}
      {processing && processingLabel ? processingLabel : children}
    </Button>
  );
}
