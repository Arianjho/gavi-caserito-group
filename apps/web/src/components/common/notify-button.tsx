import { MessageCircle } from "lucide-react";

import { Button } from "@/components/ui/button";
import { useGuardedMutation } from "@/hooks/use-guarded-mutation";
import { api } from "@/lib/api";

/** Propiedades de {@link NotifyButton}. */
export interface NotifyButtonProps {
  /** Ruta de la API que compone y encola el aviso (por ejemplo `/sales/{id}/notify`). */
  path: string;
  /** Texto del botón. */
  label?: string;
}

/**
 * Botón "Avisar por WhatsApp": pide al servidor componer y encolar el aviso.
 * El servidor valida consentimiento y teléfono; los errores salen como aviso.
 */
export function NotifyButton({ path, label = "Avisar" }: NotifyButtonProps) {
  const notify = useGuardedMutation({
    mutationFn: (_input: void, { signal }) => api(path, { method: "POST", signal }),
    successMessage: "Aviso enviado a WhatsApp",
  });

  return (
    <Button
      variant="ghost"
      size="sm"
      disabled={notify.isProcessing}
      aria-busy={notify.isProcessing}
      onClick={() => void notify.run()}
    >
      <MessageCircle /> {notify.isProcessing ? "Enviando…" : label}
    </Button>
  );
}
