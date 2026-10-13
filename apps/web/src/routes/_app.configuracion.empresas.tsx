import { createFileRoute } from "@tanstack/react-router";
import { Building2, Plus } from "lucide-react";
import { useState } from "react";

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
  type Company,
} from "@/features/organization/resources";
import { useGuardedMutation } from "@/hooks/use-guarded-mutation";
import { api } from "@/lib/api";

const PATH = "companies";

/** Datos del formulario de empresa. */
interface CompanyForm {
  legal_name: string;
  trade_name: string;
  tax_id: string;
  address: string;
}

const emptyForm: CompanyForm = { legal_name: "", trade_name: "", tax_id: "", address: "" };

/**
 * Pantalla de empresas: una por RUC. Permite crear, editar, desactivar y eliminar.
 */
function CompaniesPage() {
  const list = useResourceList<Company>(PATH);
  const invalidate = useInvalidateResource(PATH);
  const [open, setOpen] = useState(false);
  const [editing, setEditing] = useState<Company | null>(null);
  const [form, setForm] = useState<CompanyForm>(emptyForm);

  const save = useGuardedMutation({
    mutationFn: (input: CompanyForm, { signal, idempotencyKey }) =>
      editing
        ? api(`/${PATH}/${editing.id}`, { method: "PUT", body: input, signal })
        : api(`/${PATH}`, { method: "POST", body: input, signal, idempotencyKey }),
    successMessage: () => (editing ? "Empresa actualizada" : "Empresa registrada"),
    onSuccess: () => {
      setOpen(false);
      void invalidate();
    },
  });

  const openCreate = () => {
    save.reset();
    setEditing(null);
    setForm(emptyForm);
    setOpen(true);
  };

  const openEdit = (company: Company) => {
    save.reset();
    setEditing(company);
    setForm({
      legal_name: company.legal_name,
      trade_name: company.trade_name ?? "",
      tax_id: company.tax_id,
      address: company.address ?? "",
    });
    setOpen(true);
  };

  const field = (name: keyof CompanyForm, label: string, extra?: { maxLength?: number }) => {
    const error = save.fieldErrors[name]?.[0];
    return (
      <div className="grid gap-2">
        <Label htmlFor={name}>{label}</Label>
        <Input
          id={name}
          value={form[name]}
          maxLength={extra?.maxLength}
          aria-invalid={error !== undefined}
          onChange={(event) => setForm({ ...form, [name]: event.target.value })}
        />
        {error && <p className="text-xs text-destructive">{error}</p>}
      </div>
    );
  };

  return (
    <DashboardLayout
      title="Empresas"
      description="Cada empresa corresponde a un RUC. Tus sucursales y almacenes cuelgan de ellas."
      actions={
        <Button onClick={openCreate}>
          <Plus /> Nueva empresa
        </Button>
      }
    >
      {list.isPending && (
        <div className="space-y-2">
          <Skeleton className="h-10 w-full" />
          <Skeleton className="h-10 w-full" />
        </div>
      )}
      {list.isError && <ErrorState onRetry={() => void list.refetch()} />}
      {list.data?.length === 0 && (
        <EmptyState
          icon={Building2}
          title="Aún no tienes empresas"
          description="Registra tu primera empresa con su RUC para empezar a vender."
          action={<Button onClick={openCreate}>Registrar empresa</Button>}
        />
      )}
      {list.data && list.data.length > 0 && (
        <Card className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Razón social</TableHead>
                <TableHead>RUC</TableHead>
                <TableHead>Estado</TableHead>
                <TableHead className="w-10" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {list.data.map((company) => (
                <TableRow key={company.id}>
                  <TableCell>
                    <div className="font-medium">{company.legal_name}</div>
                    {company.trade_name && (
                      <div className="text-xs text-muted-foreground">{company.trade_name}</div>
                    )}
                  </TableCell>
                  <TableCell className="tabular-nums">{company.tax_id}</TableCell>
                  <TableCell>
                    <StatusChip
                      label={company.status === "active" ? "Activa" : "Inactiva"}
                      tone={company.status === "active" ? "success" : "neutral"}
                    />
                  </TableCell>
                  <TableCell>
                    <ResourceActions
                      path={PATH}
                      id={company.id}
                      name={company.legal_name}
                      status={company.status}
                      onEdit={() => openEdit(company)}
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
        title={editing ? "Editar empresa" : "Nueva empresa"}
        description="Los datos fiscales se usan en tus comprobantes."
        submitLabel={editing ? "Guardar cambios" : "Registrar empresa"}
        processingLabel={editing ? "Guardando…" : "Registrando…"}
        processing={save.isProcessing}
        slow={save.isSlow}
        onSubmit={() => void save.run(form)}
      >
        {field("tax_id", "RUC", { maxLength: 11 })}
        {field("legal_name", "Razón social")}
        {field("trade_name", "Nombre comercial (opcional)")}
        {field("address", "Dirección fiscal (opcional)")}
      </FormDialog>
    </DashboardLayout>
  );
}

export const Route = createFileRoute("/_app/configuracion/empresas")({
  component: CompaniesPage,
});
