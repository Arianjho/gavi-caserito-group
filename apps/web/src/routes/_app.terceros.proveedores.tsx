import { createFileRoute } from "@tanstack/react-router";
import { useQuery } from "@tanstack/react-query";
import { Plus, Search, Users } from "lucide-react";
import { useEffect, useState } from "react";

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
import { useInvalidateResource, type LifecycleStatus } from "@/features/organization/resources";
import { useGuardedMutation } from "@/hooks/use-guarded-mutation";
import { api } from "@/lib/api";

/** Cliente tal como lo entrega la API. */
interface Supplier {
  id: string;
  status: LifecycleStatus;
  doc_type: "DNI" | "RUC" | "CE" | "PAS" | "NONE";
  doc_number: string | null;
  name: string;
  phone: string | null;
  email: string | null;
  address: string | null;
}

/** Datos del formulario de proveedor. */
interface SupplierForm {
  doc_type: Supplier["doc_type"];
  doc_number: string;
  name: string;
  phone: string;
  email: string;
  address: string;
}

const emptyForm: SupplierForm = {
  doc_type: "DNI",
  doc_number: "",
  name: "",
  phone: "",
  email: "",
  address: "",
};

/**
 * Pantalla de clientes: búsqueda por nombre, documento o teléfono, y alta/edición.
 */
function SuppliersPage() {
  const [term, setTerm] = useState("");
  const [debounced, setDebounced] = useState("");
  useEffect(() => {
    const timer = window.setTimeout(() => setDebounced(term.trim()), 300);
    return () => window.clearTimeout(timer);
  }, [term]);

  const list = useQuery({
    queryKey: ["resource", "suppliers", debounced],
    queryFn: async () =>
      (await api<{ data: Supplier[] }>(`/suppliers?q=${encodeURIComponent(debounced)}`)).data,
  });
  const invalidate = useInvalidateResource("suppliers");

  const [open, setOpen] = useState(false);
  const [editing, setEditing] = useState<Supplier | null>(null);
  const [form, setForm] = useState<SupplierForm>(emptyForm);

  const save = useGuardedMutation({
    mutationFn: (input: SupplierForm, { signal, idempotencyKey }) => {
      const body = {
        ...input,
        doc_number: input.doc_type === "NONE" ? null : input.doc_number,
        phone: input.phone || null,
        email: input.email || null,
        address: input.address || null,
      };
      return editing
        ? api(`/suppliers/${editing.id}`, { method: "PUT", body, signal })
        : api("/suppliers", { method: "POST", body, signal, idempotencyKey });
    },
    successMessage: () => (editing ? "Proveedor actualizado" : "Proveedor registrado"),
    onSuccess: () => {
      setOpen(false);
      void invalidate();
    },
  });

  const openDialog = (supplier: Supplier | null) => {
    save.reset();
    setEditing(supplier);
    setForm(
      supplier
        ? {
            doc_type: supplier.doc_type,
            doc_number: supplier.doc_number ?? "",
            name: supplier.name,
            phone: supplier.phone ?? "",
            email: supplier.email ?? "",
            address: supplier.address ?? "",
          }
        : emptyForm,
    );
    setOpen(true);
  };

  const err = (name: string) => save.fieldErrors[name]?.[0];
  const text = (name: Exclude<keyof SupplierForm, "doc_type">, label: string) => (
    <div className="grid gap-2">
      <Label htmlFor={`c-${name}`}>{label}</Label>
      <Input
        id={`c-${name}`}
        value={form[name]}
        onChange={(event) => setForm({ ...form, [name]: event.target.value })}
        aria-invalid={err(name) !== undefined}
      />
      {err(name) && <p className="text-xs text-destructive">{err(name)}</p>}
    </div>
  );

  return (
    <DashboardLayout
      title="Proveedores"
      description="A quién le vendes, con sus datos de contacto y documento."
      actions={
        <Button onClick={() => openDialog(null)}>
          <Plus /> Nuevo proveedor
        </Button>
      }
    >
      <div className="relative mb-4 max-w-md">
        <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
        <Input
          value={term}
          onChange={(event) => setTerm(event.target.value)}
          placeholder="Buscar por nombre, documento o teléfono"
          className="pl-9"
          aria-label="Buscar proveedores"
        />
      </div>

      {list.isPending && <Skeleton className="h-24 w-full" />}
      {list.isError && <ErrorState onRetry={() => void list.refetch()} />}
      {list.data?.length === 0 && (
        <EmptyState
          icon={Users}
          title={debounced ? "Sin resultados" : "Aún no tienes proveedores"}
          description={
            debounced
              ? "Prueba con otro nombre, documento o teléfono."
              : "Registra tu primer proveedor para cargar compras."
          }
          action={
            debounced ? undefined : (
              <Button onClick={() => openDialog(null)}>Nuevo proveedor</Button>
            )
          }
        />
      )}
      {list.data && list.data.length > 0 && (
        <Card className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Proveedor</TableHead>
                <TableHead>Documento</TableHead>
                <TableHead>Teléfono</TableHead>
                <TableHead>Estado</TableHead>
                <TableHead className="w-10" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {list.data.map((supplier) => (
                <TableRow key={supplier.id}>
                  <TableCell className="font-medium">{supplier.name}</TableCell>
                  <TableCell className="tabular-nums">
                    {supplier.doc_type === "NONE"
                      ? "—"
                      : `${supplier.doc_type} ${supplier.doc_number ?? ""}`}
                  </TableCell>
                  <TableCell className="tabular-nums">{supplier.phone ?? "—"}</TableCell>
                  <TableCell>
                    <StatusChip
                      label={supplier.status === "active" ? "Activo" : "Inactivo"}
                      tone={supplier.status === "active" ? "success" : "neutral"}
                    />
                  </TableCell>
                  <TableCell>
                    <ResourceActions
                      path="suppliers"
                      id={supplier.id}
                      name={supplier.name}
                      status={supplier.status}
                      onEdit={() => openDialog(supplier)}
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
        title={editing ? "Editar proveedor" : "Nuevo proveedor"}
        submitLabel={editing ? "Guardar cambios" : "Registrar proveedor"}
        processingLabel={editing ? "Guardando…" : "Registrando…"}
        processing={save.isProcessing}
        slow={save.isSlow}
        onSubmit={() => void save.run(form)}
      >
        <div className="grid grid-cols-[8rem_1fr] gap-3">
          <div className="grid gap-2">
            <Label htmlFor="c-doc_type">Documento</Label>
            <select
              id="c-doc_type"
              value={form.doc_type}
              onChange={(event) =>
                setForm({ ...form, doc_type: event.target.value as SupplierForm["doc_type"] })
              }
              className="h-9 rounded-md border border-input bg-background px-3 text-sm"
            >
              <option value="DNI">DNI</option>
              <option value="RUC">RUC</option>
              <option value="CE">C. extranjería</option>
              <option value="PAS">Pasaporte</option>
              <option value="NONE">Sin documento</option>
            </select>
          </div>
          {form.doc_type !== "NONE" && text("doc_number", "Número")}
        </div>
        {text("name", form.doc_type === "RUC" ? "Razón social" : "Nombre completo")}
        <div className="grid grid-cols-2 gap-3">
          {text("phone", "Teléfono / WhatsApp")}
          {text("email", "Correo")}
        </div>
        {text("address", "Dirección")}
      </FormDialog>
    </DashboardLayout>
  );
}

export const Route = createFileRoute("/_app/terceros/proveedores")({
  component: SuppliersPage,
});
