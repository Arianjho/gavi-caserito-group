import { createFileRoute } from "@tanstack/react-router";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus } from "lucide-react";
import { useMemo, useState } from "react";

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
import { useGuardedMutation } from "@/hooks/use-guarded-mutation";
import { api } from "@/lib/api";

/** Perfil de acceso con sus permisos. */
interface RoleRow {
  id: string;
  name: string;
  description: string | null;
  is_system: boolean;
  status: "active" | "inactive";
  permissions: string[];
  denied: string[];
}

interface RoleForm {
  name: string;
  description: string;
  permissions: string[];
}

const emptyForm: RoleForm = { name: "", description: "", permissions: [] };

const moduleLabels: Record<string, string> = {
  catalog: "Catálogo",
  parties: "Terceros",
  orders: "Pedidos",
  sales: "Ventas",
  purchases: "Compras",
  inventory: "Inventario",
  shipping: "Envíos",
  payments: "Cobros y pagos",
  settings: "Configuración",
  fiscal: "Facturación",
  cash: "Caja",
  reports: "Reportes",
  import: "Importación",
  audit: "Auditoría",
};

const actionLabels: Record<string, string> = {
  view: "ver",
  create: "crear",
  update: "editar",
  delete: "eliminar",
};

/** Agrupa los códigos `modulo.recurso.accion` por módulo y recurso. */
function groupPermissions(codes: string[]): Map<string, Map<string, string[]>> {
  const groups = new Map<string, Map<string, string[]>>();
  for (const code of codes) {
    const [module = "", resource = "", ...action] = code.split(".");
    const resources = groups.get(module) ?? new Map<string, string[]>();
    const actions = resources.get(resource) ?? [];
    actions.push(action.join("."));
    resources.set(resource, actions);
    groups.set(module, resources);
  }
  return groups;
}

/**
 * Perfiles de acceso: matriz de permisos por módulo y recurso. Los perfiles
 * del sistema no se editan; se pueden duplicar creando uno nuevo.
 */
function RolesPage() {
  const queryClient = useQueryClient();
  const roles = useQuery({
    queryKey: ["roles"],
    queryFn: async () => (await api<{ data: RoleRow[] }>("/roles")).data,
  });
  const catalog = useQuery({
    queryKey: ["permissions"],
    queryFn: async () => (await api<{ data: string[] }>("/permissions")).data,
    staleTime: Infinity,
  });
  const groups = useMemo(() => groupPermissions(catalog.data ?? []), [catalog.data]);
  const refresh = () => void queryClient.invalidateQueries({ queryKey: ["roles"] });

  const [open, setOpen] = useState(false);
  const [editing, setEditing] = useState<RoleRow | null>(null);
  const [form, setForm] = useState<RoleForm>(emptyForm);

  const save = useGuardedMutation({
    mutationFn: (input: RoleForm, { signal, idempotencyKey }) =>
      editing
        ? api(`/roles/${editing.id}`, { method: "PUT", body: input, signal })
        : api("/roles", { method: "POST", body: input, signal, idempotencyKey }),
    successMessage: () => (editing ? "Perfil actualizado" : "Perfil creado"),
    onSuccess: () => {
      setOpen(false);
      refresh();
    },
  });

  const toggle = useGuardedMutation({
    mutationFn: (input: { id: string; active: boolean }, { signal }) =>
      api(`/roles/${input.id}/active`, {
        method: "PATCH",
        body: { active: input.active },
        signal,
      }),
    successMessage: "Perfil actualizado",
    onSuccess: refresh,
  });

  const openDialog = (role: RoleRow | null) => {
    save.reset();
    setEditing(role);
    setForm(
      role
        ? { name: role.name, description: role.description ?? "", permissions: role.permissions }
        : emptyForm,
    );
    setOpen(true);
  };

  const togglePermission = (code: string) =>
    setForm((current) => ({
      ...current,
      permissions: current.permissions.includes(code)
        ? current.permissions.filter((item) => item !== code)
        : [...current.permissions, code],
    }));

  const error = (name: string) => save.fieldErrors[name]?.[0];

  return (
    <DashboardLayout
      title="Perfiles"
      description="Qué puede ver y hacer cada tipo de usuario."
      actions={
        <Button onClick={() => openDialog(null)}>
          <Plus /> Nuevo perfil
        </Button>
      }
    >
      {roles.isPending && <Skeleton className="h-24 w-full" />}
      {roles.isError && <ErrorState onRetry={() => void roles.refetch()} />}
      {roles.data?.length === 0 && (
        <EmptyState title="Sin perfiles" description="Crea el primer perfil de acceso." />
      )}
      {roles.data && roles.data.length > 0 && (
        <Card className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Perfil</TableHead>
                <TableHead>Descripción</TableHead>
                <TableHead>Permisos</TableHead>
                <TableHead>Estado</TableHead>
                <TableHead className="w-44" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {roles.data.map((role) => (
                <TableRow key={role.id}>
                  <TableCell className="font-medium">
                    {role.name}
                    {role.is_system && (
                      <span className="ml-2 text-xs text-muted-foreground">(del sistema)</span>
                    )}
                  </TableCell>
                  <TableCell>{role.description ?? "—"}</TableCell>
                  <TableCell>{role.permissions.length}</TableCell>
                  <TableCell>
                    <StatusChip
                      label={role.status === "active" ? "Activo" : "Inactivo"}
                      tone={role.status === "active" ? "success" : "neutral"}
                    />
                  </TableCell>
                  <TableCell className="space-x-1 text-right">
                    <Button variant="ghost" size="sm" onClick={() => openDialog(role)}>
                      {role.is_system ? "Ver" : "Editar"}
                    </Button>
                    {!role.is_system && (
                      <Button
                        variant="ghost"
                        size="sm"
                        disabled={toggle.isProcessing}
                        onClick={() =>
                          void toggle.run({ id: role.id, active: role.status !== "active" })
                        }
                      >
                        {role.status === "active" ? "Desactivar" : "Activar"}
                      </Button>
                    )}
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
        title={
          editing
            ? editing.is_system
              ? `Perfil: ${editing.name}`
              : "Editar perfil"
            : "Nuevo perfil"
        }
        submitLabel={editing ? "Guardar cambios" : "Crear perfil"}
        processingLabel="Guardando…"
        processing={save.isProcessing}
        slow={save.isSlow}
        onSubmit={() => void save.run(form)}
      >
        <div className="grid gap-2">
          <Label htmlFor="r-name">Nombre</Label>
          <Input
            id="r-name"
            value={form.name}
            disabled={editing?.is_system}
            onChange={(event) => setForm({ ...form, name: event.target.value })}
            aria-invalid={error("name") !== undefined}
          />
          {error("name") && <p className="text-xs text-destructive">{error("name")}</p>}
        </div>
        <div className="grid gap-2">
          <Label htmlFor="r-desc">Descripción</Label>
          <Input
            id="r-desc"
            value={form.description}
            disabled={editing?.is_system}
            onChange={(event) => setForm({ ...form, description: event.target.value })}
          />
        </div>
        <div className="max-h-80 space-y-4 overflow-y-auto rounded-md border p-3">
          {[...groups.entries()].map(([module, resources]) => (
            <fieldset key={module} className="space-y-1">
              <legend className="text-sm font-semibold">{moduleLabels[module] ?? module}</legend>
              {[...resources.entries()].map(([resource, actions]) => (
                <div key={resource} className="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                  <span className="w-36 shrink-0 text-muted-foreground">{resource}</span>
                  {actions.map((action) => {
                    const code = `${module}.${resource}.${action}`;
                    return (
                      <label key={code} className="flex items-center gap-1">
                        <input
                          type="checkbox"
                          disabled={editing?.is_system}
                          checked={form.permissions.includes(code)}
                          onChange={() => togglePermission(code)}
                        />
                        {actionLabels[action] ?? action}
                      </label>
                    );
                  })}
                </div>
              ))}
            </fieldset>
          ))}
        </div>
      </FormDialog>
    </DashboardLayout>
  );
}

export const Route = createFileRoute("/_app/configuracion/perfiles")({
  component: RolesPage,
});
