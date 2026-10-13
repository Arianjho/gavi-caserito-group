import { Plus } from "lucide-react";
import { useState, type ReactNode } from "react";

import { EmptyState } from "@/components/common/empty-state";
import { ErrorState } from "@/components/common/error-state";
import { FormDialog } from "@/components/common/form-dialog";
import { StatusChip } from "@/components/common/status-chip";
import { DashboardLayout } from "@/components/dashboard/dashboard-layout";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { ResourceActions } from "@/features/organization/resource-actions";
import {
  useInvalidateResource,
  useResourceList,
  type LifecycleStatus,
} from "@/features/organization/resources";
import { useGuardedMutation } from "@/hooks/use-guarded-mutation";
import { api } from "@/lib/api";

/** Registro mínimo de un maestro con solo nombre. */
export interface SimpleRecord {
  id: string;
  name: string;
  status: LifecycleStatus;
}

/** Propiedades de {@link SimpleMasterPage}. */
interface SimpleMasterPageProps {
  /** Ruta del recurso en la API (por ejemplo `brands`). */
  path: string;
  /** Título de la página (plural). */
  title: string;
  /** Descripción de la página. */
  description: string;
  /** Nombre singular en minúscula para los textos ("marca"). */
  singular: string;
  /** Género gramatical: `f` para femenino ("nueva marca"), `m` para masculino. */
  gender: "f" | "m";
  /** Acciones adicionales por fila (por ejemplo "Precios"). */
  rowActions?: (record: SimpleRecord) => ReactNode;
}

/**
 * Pantalla estándar para maestros que solo tienen nombre (marcas, categorías).
 *
 * Reúne listado, alta, edición, desactivación y eliminación con guardia.
 */
export function SimpleMasterPage({
  path,
  title,
  description,
  singular,
  gender,
  rowActions,
}: SimpleMasterPageProps) {
  const list = useResourceList<SimpleRecord>(path);
  const invalidate = useInvalidateResource(path);
  const [open, setOpen] = useState(false);
  const [editing, setEditing] = useState<SimpleRecord | null>(null);
  const [name, setName] = useState("");

  const newLabel = `${gender === "f" ? "Nueva" : "Nuevo"} ${singular}`;

  const save = useGuardedMutation({
    mutationFn: (input: { name: string }, { signal, idempotencyKey }) =>
      editing
        ? api(`/${path}/${editing.id}`, { method: "PUT", body: input, signal })
        : api(`/${path}`, { method: "POST", body: input, signal, idempotencyKey }),
    successMessage: () => (editing ? "Cambios guardados" : `${newLabel.split(" ")[1]} registrado`),
    onSuccess: () => {
      setOpen(false);
      void invalidate();
    },
  });

  const openDialog = (record: SimpleRecord | null) => {
    save.reset();
    setEditing(record);
    setName(record?.name ?? "");
    setOpen(true);
  };

  const nameError = save.fieldErrors["name"]?.[0];

  return (
    <DashboardLayout
      title={title}
      description={description}
      actions={
        <Button onClick={() => openDialog(null)}>
          <Plus /> {newLabel}
        </Button>
      }
    >
      {list.isPending && <Skeleton className="h-24 w-full" />}
      {list.isError && <ErrorState onRetry={() => void list.refetch()} />}
      {list.data?.length === 0 && (
        <EmptyState
          title={`Aún no tienes ${title.toLowerCase()}`}
          description={`Crea ${gender === "f" ? "la primera" : "el primero"} para empezar.`}
          action={<Button onClick={() => openDialog(null)}>{newLabel}</Button>}
        />
      )}
      {list.data && list.data.length > 0 && (
        <Card className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Nombre</TableHead>
                <TableHead>Estado</TableHead>
                <TableHead className="w-10" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {list.data.map((record) => (
                <TableRow key={record.id}>
                  <TableCell className="font-medium">{record.name}</TableCell>
                  <TableCell>
                    <StatusChip
                      label={record.status === "active" ? "Activo" : "Inactivo"}
                      tone={record.status === "active" ? "success" : "neutral"}
                    />
                  </TableCell>
                  <TableCell className="space-x-1 whitespace-nowrap">
                    {rowActions?.(record)}
                    <ResourceActions
                      path={path}
                      id={record.id}
                      name={record.name}
                      status={record.status}
                      onEdit={() => openDialog(record)}
                    />
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Card>
      )}

      <FormDialog
        open={open}
        onOpenChange={setOpen}
        title={editing ? `Editar ${singular}` : newLabel}
        size="sm"
        submitLabel={editing ? "Guardar cambios" : "Registrar"}
        processingLabel={editing ? "Guardando…" : "Registrando…"}
        processing={save.isProcessing}
        slow={save.isSlow}
        onSubmit={() => void save.run({ name })}
      >
        <div className="grid gap-2">
          <Label htmlFor="name">Nombre</Label>
          <Input
            id="name"
            value={name}
            onChange={(event) => setName(event.target.value)}
            aria-invalid={nameError !== undefined}
          />
          {nameError && <p className="text-xs text-destructive">{nameError}</p>}
        </div>
      </FormDialog>
    </DashboardLayout>
  );
}
