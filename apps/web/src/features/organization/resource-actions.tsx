import { MoreHorizontal } from "lucide-react";
import { useState } from "react";
import { toast } from "sonner";

import { ConfirmDialog } from "@/components/common/confirm-dialog";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { useInvalidateResource, useSetActive, type LifecycleStatus } from "./resources";
import { useGuardedMutation } from "@/hooks/use-guarded-mutation";
import { api } from "@/lib/api";
import { ApiError } from "@/lib/api-error";

/** Propiedades de {@link ResourceActions}. */
interface ResourceActionsProps {
  /** Ruta del recurso (por ejemplo `companies`). */
  path: string;
  /** Identificador del registro. */
  id: string;
  /** Nombre para mostrar en la confirmación. */
  name: string;
  /** Estado actual. */
  status: LifecycleStatus;
  /** Permite editar el registro (abre el formulario). */
  onEdit?: () => void;
}

/**
 * Menú de acciones de una fila: editar, activar/desactivar y eliminar.
 *
 * La eliminación respeta la guardia del servidor: si el registro tiene
 * dependencias, se informa cuáles y se ofrece desactivar en su lugar.
 */
export function ResourceActions({ path, id, name, status, onEdit }: ResourceActionsProps) {
  const [confirmOpen, setConfirmOpen] = useState(false);
  const invalidate = useInvalidateResource(path);
  const setActive = useSetActive(path);

  const remove = useGuardedMutation({
    mutationFn: (_input: void, { signal }) => api(`/${path}/${id}`, { method: "DELETE", signal }),
    successMessage: `"${name}" eliminado`,
    onSuccess: () => {
      setConfirmOpen(false);
      void invalidate();
    },
  });

  const toggle = () => {
    const next = status !== "active";
    setActive.mutate(
      { id, active: next },
      {
        onSuccess: () => toast.success(next ? `"${name}" activado` : `"${name}" desactivado`),
        onError: (error) =>
          toast.error(error instanceof ApiError ? error.friendlyMessage : "No se pudo cambiar"),
      },
    );
  };

  return (
    <>
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button variant="ghost" size="icon" aria-label={`Acciones de ${name}`}>
            <MoreHorizontal className="size-4" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          {onEdit && <DropdownMenuItem onSelect={onEdit}>Editar</DropdownMenuItem>}
          <DropdownMenuItem onSelect={toggle}>
            {status === "active" ? "Desactivar" : "Activar"}
          </DropdownMenuItem>
          <DropdownMenuSeparator />
          <DropdownMenuItem className="text-destructive" onSelect={() => setConfirmOpen(true)}>
            Eliminar
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>

      <ConfirmDialog
        open={confirmOpen}
        onOpenChange={setConfirmOpen}
        title={`Eliminar "${name}"`}
        description="El registro dejará de aparecer. Si ya tiene movimientos no se podrá eliminar y te ofreceremos desactivarlo."
        processing={remove.isProcessing}
        onConfirm={() => void remove.run()}
      />
    </>
  );
}
