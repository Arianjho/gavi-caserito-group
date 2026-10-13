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
import {
  useInvalidateResource,
  useResourceList,
  type LifecycleStatus,
} from "@/features/organization/resources";
import { useGuardedMutation } from "@/hooks/use-guarded-mutation";
import { api } from "@/lib/api";

/** Cliente tal como lo entrega la API. */
interface Customer {
  id: string;
  status: LifecycleStatus;
  doc_type: "DNI" | "RUC" | "CE" | "PAS" | "NONE";
  doc_number: string | null;
  name: string;
  phone: string | null;
  email: string | null;
  address: string | null;
  price_list_id: string | null;
  whatsapp_opt_in: boolean;
}

/** Datos del formulario de cliente. */
interface CustomerForm {
  doc_type: Customer["doc_type"];
  doc_number: string;
  name: string;
  phone: string;
  email: string;
  address: string;
  price_list_id: string;
  whatsapp_opt_in: boolean;
}

const emptyForm: CustomerForm = {
  doc_type: "DNI",
  doc_number: "",
  name: "",
  phone: "",
  email: "",
  address: "",
  price_list_id: "",
  whatsapp_opt_in: false,
};

/**
 * Pantalla de clientes: búsqueda por nombre, documento o teléfono, y alta/edición.
 */
function CustomersPage() {
  const [term, setTerm] = useState("");
  const [debounced, setDebounced] = useState("");
  useEffect(() => {
    const timer = window.setTimeout(() => setDebounced(term.trim()), 300);
    return () => window.clearTimeout(timer);
  }, [term]);

  const list = useQuery({
    queryKey: ["resource", "customers", debounced],
    queryFn: async () =>
      (await api<{ data: Customer[] }>(`/customers?q=${encodeURIComponent(debounced)}`)).data,
  });
  const invalidate = useInvalidateResource("customers");
  const priceLists = (
    useResourceList<{ id: string; name: string; status: string }>("price-lists").data ?? []
  ).filter((list) => list.status === "active");

  const [open, setOpen] = useState(false);
  const [editing, setEditing] = useState<Customer | null>(null);
  const [form, setForm] = useState<CustomerForm>(emptyForm);

  const save = useGuardedMutation({
    mutationFn: (input: CustomerForm, { signal, idempotencyKey }) => {
      const body = {
        ...input,
        doc_number: input.doc_type === "NONE" ? null : input.doc_number,
        phone: input.phone || null,
        email: input.email || null,
        address: input.address || null,
        price_list_id: input.price_list_id || null,
      };
      return editing
        ? api(`/customers/${editing.id}`, { method: "PUT", body, signal })
        : api("/customers", { method: "POST", body, signal, idempotencyKey });
    },
    successMessage: () => (editing ? "Cliente actualizado" : "Cliente registrado"),
    onSuccess: () => {
      setOpen(false);
      void invalidate();
    },
  });

  const openDialog = (customer: Customer | null) => {
    save.reset();
    setEditing(customer);
    setForm(
      customer
        ? {
            doc_type: customer.doc_type,
            doc_number: customer.doc_number ?? "",
            name: customer.name,
            phone: customer.phone ?? "",
            email: customer.email ?? "",
            address: customer.address ?? "",
            price_list_id: customer.price_list_id ?? "",
            whatsapp_opt_in: customer.whatsapp_opt_in,
          }
        : emptyForm,
    );
    setOpen(true);
  };

  const err = (name: string) => save.fieldErrors[name]?.[0];
  const text = (
    name: Exclude<keyof CustomerForm, "doc_type" | "price_list_id" | "whatsapp_opt_in">,
    label: string,
  ) => (
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
      title="Clientes"
      description="A quién le vendes, con sus datos de contacto y documento."
      actions={
        <Button onClick={() => openDialog(null)}>
          <Plus /> Nuevo cliente
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
          aria-label="Buscar clientes"
        />
      </div>

      {list.isPending && <Skeleton className="h-24 w-full" />}
      {list.isError && <ErrorState onRetry={() => void list.refetch()} />}
      {list.data?.length === 0 && (
        <EmptyState
          icon={Users}
          title={debounced ? "Sin resultados" : "Aún no tienes clientes"}
          description={
            debounced
              ? "Prueba con otro nombre, documento o teléfono."
              : "Registra tu primer cliente o impórtalos desde Excel."
          }
          action={
            debounced ? undefined : <Button onClick={() => openDialog(null)}>Nuevo cliente</Button>
          }
        />
      )}
      {list.data && list.data.length > 0 && (
        <Card className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Cliente</TableHead>
                <TableHead>Documento</TableHead>
                <TableHead>Teléfono</TableHead>
                <TableHead>Estado</TableHead>
                <TableHead className="w-10" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {list.data.map((customer) => (
                <TableRow key={customer.id}>
                  <TableCell className="font-medium">{customer.name}</TableCell>
                  <TableCell className="tabular-nums">
                    {customer.doc_type === "NONE"
                      ? "—"
                      : `${customer.doc_type} ${customer.doc_number ?? ""}`}
                  </TableCell>
                  <TableCell className="tabular-nums">{customer.phone ?? "—"}</TableCell>
                  <TableCell>
                    <StatusChip
                      label={customer.status === "active" ? "Activo" : "Inactivo"}
                      tone={customer.status === "active" ? "success" : "neutral"}
                    />
                  </TableCell>
                  <TableCell>
                    <ResourceActions
                      path="customers"
                      id={customer.id}
                      name={customer.name}
                      status={customer.status}
                      onEdit={() => openDialog(customer)}
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
        title={editing ? "Editar cliente" : "Nuevo cliente"}
        submitLabel={editing ? "Guardar cambios" : "Registrar cliente"}
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
                setForm({ ...form, doc_type: event.target.value as CustomerForm["doc_type"] })
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
        <label className="flex items-start gap-2 text-sm">
          <input
            type="checkbox"
            className="mt-0.5"
            checked={form.whatsapp_opt_in}
            onChange={(event) => setForm({ ...form, whatsapp_opt_in: event.target.checked })}
          />
          <span>
            El cliente aceptó recibir avisos por WhatsApp (comprobantes, pedidos, deudas).
          </span>
        </label>
        {priceLists.length > 0 && (
          <div className="grid gap-2">
            <Label htmlFor="c-price_list">Lista de precios</Label>
            <select
              id="c-price_list"
              value={form.price_list_id}
              onChange={(event) => setForm({ ...form, price_list_id: event.target.value })}
              className="h-9 rounded-md border border-input bg-background px-3 text-sm"
            >
              <option value="">Precio base</option>
              {priceLists.map((list) => (
                <option key={list.id} value={list.id}>
                  {list.name}
                </option>
              ))}
            </select>
          </div>
        )}
      </FormDialog>
    </DashboardLayout>
  );
}

export const Route = createFileRoute("/_app/terceros/clientes")({
  component: CustomersPage,
});
