import { createFileRoute } from "@tanstack/react-router";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus } from "lucide-react";
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
import { useGuardedMutation } from "@/hooks/use-guarded-mutation";
import { api } from "@/lib/api";

/** Usuario de la cuenta. */
interface UserRow {
  id: string;
  name: string;
  email: string;
  status: "active" | "inactive";
  is_owner: boolean;
  last_login_at: string | null;
  roles: { id: string; name: string }[];
}

/** Perfil asignable. */
interface RoleRow {
  id: string;
  name: string;
  status: string;
}

interface UserForm {
  name: string;
  email: string;
  password: string;
  role_ids: string[];
}

const emptyForm: UserForm = { name: "", email: "", password: "", role_ids: [] };

/**
 * Usuarios de la cuenta: alta con perfil, edición, cambio de contraseña y
 * activación. El propietario está protegido.
 */
function UsersPage() {
  const queryClient = useQueryClient();
  const users = useQuery({
    queryKey: ["users"],
    queryFn: async () => (await api<{ data: UserRow[] }>("/users")).data,
  });
  const roles = useQuery({
    queryKey: ["roles"],
    queryFn: async () => (await api<{ data: RoleRow[] }>("/roles")).data,
  });
  const activeRoles = (roles.data ?? []).filter((role) => role.status === "active");
  const refresh = () => void queryClient.invalidateQueries({ queryKey: ["users"] });

  const [open, setOpen] = useState(false);
  const [editing, setEditing] = useState<UserRow | null>(null);
  const [form, setForm] = useState<UserForm>(emptyForm);

  const save = useGuardedMutation({
    mutationFn: (input: UserForm, { signal, idempotencyKey }) => {
      const body = {
        name: input.name,
        role_ids: input.role_ids,
        password: input.password || null,
        ...(editing ? {} : { email: input.email }),
      };
      return editing
        ? api(`/users/${editing.id}`, { method: "PUT", body, signal })
        : api("/users", { method: "POST", body, signal, idempotencyKey });
    },
    successMessage: () => (editing ? "Usuario actualizado" : "Usuario creado"),
    onSuccess: () => {
      setOpen(false);
      refresh();
    },
  });

  const toggle = useGuardedMutation({
    mutationFn: (input: { id: string; active: boolean }, { signal }) =>
      api(`/users/${input.id}/active`, {
        method: "PATCH",
        body: { active: input.active },
        signal,
      }),
    successMessage: "Usuario actualizado",
    onSuccess: refresh,
  });

  const openDialog = (user: UserRow | null) => {
    save.reset();
    setEditing(user);
    setForm(
      user
        ? {
            name: user.name,
            email: user.email,
            password: "",
            role_ids: user.roles.map((r) => r.id),
          }
        : emptyForm,
    );
    setOpen(true);
  };

  const toggleRole = (id: string) =>
    setForm((current) => ({
      ...current,
      role_ids: current.role_ids.includes(id)
        ? current.role_ids.filter((roleId) => roleId !== id)
        : [...current.role_ids, id],
    }));

  const error = (name: string) => save.fieldErrors[name]?.[0];

  return (
    <DashboardLayout
      title="Usuarios"
      description="Quién entra al sistema y qué puede hacer."
      actions={
        <Button onClick={() => openDialog(null)}>
          <Plus /> Nuevo usuario
        </Button>
      }
    >
      {users.isPending && <Skeleton className="h-24 w-full" />}
      {users.isError && <ErrorState onRetry={() => void users.refetch()} />}
      {users.data?.length === 0 && (
        <EmptyState title="Sin usuarios" description="Crea el primer usuario de tu equipo." />
      )}
      {users.data && users.data.length > 0 && (
        <Card className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Nombre</TableHead>
                <TableHead>Correo</TableHead>
                <TableHead>Perfiles</TableHead>
                <TableHead>Estado</TableHead>
                <TableHead className="w-44" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {users.data.map((user) => (
                <TableRow key={user.id}>
                  <TableCell className="font-medium">
                    {user.name}
                    {user.is_owner && (
                      <span className="ml-2 text-xs text-muted-foreground">(propietario)</span>
                    )}
                  </TableCell>
                  <TableCell>{user.email}</TableCell>
                  <TableCell>{user.roles.map((role) => role.name).join(", ") || "—"}</TableCell>
                  <TableCell>
                    <StatusChip
                      label={user.status === "active" ? "Activo" : "Inactivo"}
                      tone={user.status === "active" ? "success" : "neutral"}
                    />
                  </TableCell>
                  <TableCell className="space-x-1 text-right">
                    <Button variant="ghost" size="sm" onClick={() => openDialog(user)}>
                      Editar
                    </Button>
                    {!user.is_owner && (
                      <Button
                        variant="ghost"
                        size="sm"
                        disabled={toggle.isProcessing}
                        onClick={() =>
                          void toggle.run({ id: user.id, active: user.status !== "active" })
                        }
                      >
                        {user.status === "active" ? "Desactivar" : "Activar"}
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
        title={editing ? "Editar usuario" : "Nuevo usuario"}
        submitLabel={editing ? "Guardar cambios" : "Crear usuario"}
        processingLabel="Guardando…"
        processing={save.isProcessing}
        slow={save.isSlow}
        onSubmit={() => void save.run(form)}
      >
        <div className="grid gap-2">
          <Label htmlFor="u-name">Nombre</Label>
          <Input
            id="u-name"
            value={form.name}
            onChange={(event) => setForm({ ...form, name: event.target.value })}
            aria-invalid={error("name") !== undefined}
          />
          {error("name") && <p className="text-xs text-destructive">{error("name")}</p>}
        </div>
        <div className="grid gap-2">
          <Label htmlFor="u-email">Correo</Label>
          <Input
            id="u-email"
            type="email"
            disabled={editing !== null}
            value={form.email}
            onChange={(event) => setForm({ ...form, email: event.target.value })}
            aria-invalid={error("email") !== undefined}
          />
          {error("email") && <p className="text-xs text-destructive">{error("email")}</p>}
        </div>
        <div className="grid gap-2">
          <Label htmlFor="u-password">
            {editing ? "Nueva contraseña (opcional)" : "Contraseña (mínimo 10 caracteres)"}
          </Label>
          <Input
            id="u-password"
            type="password"
            autoComplete="new-password"
            value={form.password}
            onChange={(event) => setForm({ ...form, password: event.target.value })}
            aria-invalid={error("password") !== undefined}
          />
          {error("password") && <p className="text-xs text-destructive">{error("password")}</p>}
        </div>
        {!editing?.is_owner && (
          <fieldset className="grid gap-2">
            <legend className="text-sm font-medium">Perfiles</legend>
            {activeRoles.map((role) => (
              <label key={role.id} className="flex items-center gap-2 text-sm">
                <input
                  type="checkbox"
                  checked={form.role_ids.includes(role.id)}
                  onChange={() => toggleRole(role.id)}
                />
                {role.name}
              </label>
            ))}
            {error("role_ids") && <p className="text-xs text-destructive">{error("role_ids")}</p>}
          </fieldset>
        )}
      </FormDialog>
    </DashboardLayout>
  );
}

export const Route = createFileRoute("/_app/configuracion/usuarios")({
  component: UsersPage,
});
