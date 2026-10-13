import { useQuery, useQueryClient } from "@tanstack/react-query";
import { createFileRoute, redirect, useNavigate } from "@tanstack/react-router";
import { LogOut, Plus } from "lucide-react";
import { useState } from "react";

import { ConfirmDialog } from "@/components/common/confirm-dialog";
import { EmptyState } from "@/components/common/empty-state";
import { ErrorState } from "@/components/common/error-state";
import { FormDialog } from "@/components/common/form-dialog";
import { StatusChip } from "@/components/common/status-chip";
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
import { api, getOperatorToken, setOperatorToken } from "@/lib/api";

type PlanStatus = "trial" | "active" | "suspended" | "closed";

/** Cuenta con su uso. */
interface TenantRow {
  id: string;
  name: string;
  plan_code: string;
  plan_status: PlanStatus;
  trial_ends_at: string | null;
  max_users: number | null;
  max_companies: number | null;
  max_documents_month: number | null;
  users_used: number;
  companies_used: number;
  documents_month: number;
  last_login_at: string | null;
  created_at: string;
}

const STATUS: Record<
  PlanStatus,
  { label: string; tone: "info" | "success" | "danger" | "neutral" }
> = {
  trial: { label: "En prueba", tone: "info" },
  active: { label: "Activa", tone: "success" },
  suspended: { label: "Suspendida", tone: "danger" },
  closed: { label: "Cerrada", tone: "neutral" },
};

/** Texto "usado / tope". */
function usage(used: number, max: number | null): string {
  return `${used} / ${max ?? "∞"}`;
}

/** Convierte el campo de texto de un tope a número o `null` (sin tope). */
function toLimit(value: string): number | null {
  const number = Number(value);
  return value.trim() === "" || !Number.isFinite(number) || number < 1 ? null : Math.floor(number);
}

/**
 * Panel del proveedor: cuentas con su uso, y acciones para crear una cuenta,
 * cambiar plan y límites, y suspender o reactivar.
 */
function OperatorPanelPage() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [term, setTerm] = useState("");
  const list = useQuery({
    queryKey: ["operator", "tenants", term],
    queryFn: async () =>
      (
        await api<{ data: TenantRow[] }>(`/operator/tenants?q=${encodeURIComponent(term)}`, {
          scope: "operator",
        })
      ).data,
  });
  const refresh = () => void queryClient.invalidateQueries({ queryKey: ["operator", "tenants"] });

  // Edición de plan
  const [editing, setEditing] = useState<TenantRow | null>(null);
  const [planCode, setPlanCode] = useState("");
  const [status, setStatus] = useState<PlanStatus>("trial");
  const [trialEnds, setTrialEnds] = useState("");
  const [maxUsers, setMaxUsers] = useState("");
  const [maxCompanies, setMaxCompanies] = useState("");
  const [maxDocs, setMaxDocs] = useState("");

  const openEdit = (row: TenantRow) => {
    savePlan.reset();
    setEditing(row);
    setPlanCode(row.plan_code);
    setStatus(row.plan_status);
    setTrialEnds(row.trial_ends_at ? row.trial_ends_at.slice(0, 10) : "");
    setMaxUsers(row.max_users?.toString() ?? "");
    setMaxCompanies(row.max_companies?.toString() ?? "");
    setMaxDocs(row.max_documents_month?.toString() ?? "");
  };

  const savePlan = useGuardedMutation({
    mutationFn: (_input: void, { signal }) =>
      api(`/operator/tenants/${editing?.id}/plan`, {
        method: "PUT",
        scope: "operator",
        signal,
        body: {
          plan_code: planCode,
          plan_status: status,
          trial_ends_at: trialEnds || null,
          max_users: toLimit(maxUsers),
          max_companies: toLimit(maxCompanies),
          max_documents_month: toLimit(maxDocs),
        },
      }),
    successMessage: "Plan actualizado",
    onSuccess: () => {
      setEditing(null);
      refresh();
    },
  });

  // Alta de cuenta
  const [creating, setCreating] = useState(false);
  const [form, setForm] = useState({
    name: "",
    owner_name: "",
    owner_email: "",
    owner_password: "",
  });
  const create = useGuardedMutation({
    mutationFn: (_input: void, { signal, idempotencyKey }) =>
      api("/operator/tenants", {
        method: "POST",
        scope: "operator",
        signal,
        idempotencyKey,
        body: form,
      }),
    successMessage: "Cuenta creada con 14 días de prueba",
    onSuccess: () => {
      setCreating(false);
      refresh();
    },
  });

  // Suspender / reactivar
  const setAccountStatus = useGuardedMutation({
    mutationFn: (input: { id: string; plan_status: PlanStatus }, { signal }) =>
      api(`/operator/tenants/${input.id}/plan`, {
        method: "PUT",
        scope: "operator",
        signal,
        body: { plan_status: input.plan_status },
      }),
    successMessage: "Estado actualizado",
    onSuccess: refresh,
  });

  const [suspending, setSuspending] = useState<TenantRow | null>(null);

  const logout = async () => {
    try {
      await api("/operator/auth/logout", { method: "POST", scope: "operator" });
    } finally {
      setOperatorToken(null);
      void navigate({ to: "/operador/login" });
    }
  };

  const fieldError = (name: string) => create.fieldErrors[name]?.[0];

  return (
    <div className="min-h-screen bg-background">
      <header className="flex items-center justify-between border-b border-border px-6 py-3">
        <h1 className="text-lg font-bold">
          Gavi <span className="text-primary">· Panel del proveedor</span>
        </h1>
        <Button variant="ghost" size="sm" onClick={() => void logout()}>
          <LogOut /> Salir
        </Button>
      </header>

      <main className="space-y-4 p-6">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <Input
            className="max-w-xs"
            placeholder="Buscar cuenta por nombre"
            value={term}
            onChange={(event) => setTerm(event.target.value)}
          />
          <Button
            onClick={() => {
              create.reset();
              setForm({ name: "", owner_name: "", owner_email: "", owner_password: "" });
              setCreating(true);
            }}
          >
            <Plus /> Nueva cuenta
          </Button>
        </div>

        {list.isPending && <Skeleton className="h-24 w-full" />}
        {list.isError && <ErrorState onRetry={() => void list.refetch()} />}
        {list.data?.length === 0 && (
          <EmptyState title="Sin cuentas" description="Crea la primera cuenta de cliente." />
        )}
        {list.data && list.data.length > 0 && (
          <Card className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Cuenta</TableHead>
                  <TableHead>Plan</TableHead>
                  <TableHead>Estado</TableHead>
                  <TableHead className="text-right">Usuarios</TableHead>
                  <TableHead className="text-right">Empresas</TableHead>
                  <TableHead className="text-right">Docs. del mes</TableHead>
                  <TableHead>Último ingreso</TableHead>
                  <TableHead className="w-48" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {list.data.map((row) => (
                  <TableRow key={row.id}>
                    <TableCell className="font-medium">{row.name}</TableCell>
                    <TableCell>{row.plan_code}</TableCell>
                    <TableCell>
                      <StatusChip
                        label={STATUS[row.plan_status].label}
                        tone={STATUS[row.plan_status].tone}
                      />
                      {row.plan_status === "trial" && row.trial_ends_at && (
                        <span className="ml-2 text-xs text-muted-foreground">
                          hasta {new Date(row.trial_ends_at).toLocaleDateString("es-PE")}
                        </span>
                      )}
                    </TableCell>
                    <TableCell className="text-right tabular-nums">
                      {usage(row.users_used, row.max_users)}
                    </TableCell>
                    <TableCell className="text-right tabular-nums">
                      {usage(row.companies_used, row.max_companies)}
                    </TableCell>
                    <TableCell className="text-right tabular-nums">
                      {usage(row.documents_month, row.max_documents_month)}
                    </TableCell>
                    <TableCell className="whitespace-nowrap text-muted-foreground">
                      {row.last_login_at
                        ? new Date(row.last_login_at).toLocaleDateString("es-PE")
                        : "—"}
                    </TableCell>
                    <TableCell className="space-x-1 whitespace-nowrap text-right">
                      <Button variant="outline" size="sm" onClick={() => openEdit(row)}>
                        Plan y límites
                      </Button>
                      {row.plan_status === "suspended" || row.plan_status === "closed" ? (
                        <Button
                          size="sm"
                          disabled={setAccountStatus.isProcessing}
                          onClick={() =>
                            void setAccountStatus.run({ id: row.id, plan_status: "active" })
                          }
                        >
                          Reactivar
                        </Button>
                      ) : (
                        <Button
                          variant="ghost"
                          size="sm"
                          className="text-destructive"
                          disabled={setAccountStatus.isProcessing}
                          onClick={() => setSuspending(row)}
                        >
                          Suspender
                        </Button>
                      )}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </Card>
        )}
      </main>

      <FormDialog
        open={editing !== null}
        onOpenChange={(open) => !open && setEditing(null)}
        title={editing ? `Plan de ${editing.name}` : "Plan"}
        description="Deja un tope vacío para no limitarlo."
        size="sm"
        submitLabel="Guardar plan"
        processingLabel="Guardando…"
        processing={savePlan.isProcessing}
        slow={savePlan.isSlow}
        onSubmit={() => void savePlan.run()}
      >
        <div className="grid gap-2">
          <Label htmlFor="plan-code">Código del plan</Label>
          <Input id="plan-code" value={planCode} onChange={(e) => setPlanCode(e.target.value)} />
        </div>
        <div className="grid gap-2">
          <Label htmlFor="plan-status">Estado</Label>
          <select
            id="plan-status"
            value={status}
            onChange={(e) => setStatus(e.target.value as PlanStatus)}
            className="h-9 rounded-md border border-input bg-background px-3 text-sm"
          >
            {(Object.keys(STATUS) as PlanStatus[]).map((key) => (
              <option key={key} value={key}>
                {STATUS[key].label}
              </option>
            ))}
          </select>
        </div>
        <div className="grid gap-2">
          <Label htmlFor="plan-trial">Fin de la prueba</Label>
          <Input
            id="plan-trial"
            type="date"
            value={trialEnds}
            onChange={(e) => setTrialEnds(e.target.value)}
          />
        </div>
        <div className="grid grid-cols-3 gap-2">
          <div className="grid gap-2">
            <Label htmlFor="plan-users">Usuarios</Label>
            <Input
              id="plan-users"
              inputMode="numeric"
              value={maxUsers}
              onChange={(e) => setMaxUsers(e.target.value)}
            />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="plan-companies">Empresas</Label>
            <Input
              id="plan-companies"
              inputMode="numeric"
              value={maxCompanies}
              onChange={(e) => setMaxCompanies(e.target.value)}
            />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="plan-docs">Docs./mes</Label>
            <Input
              id="plan-docs"
              inputMode="numeric"
              value={maxDocs}
              onChange={(e) => setMaxDocs(e.target.value)}
            />
          </div>
        </div>
      </FormDialog>

      <ConfirmDialog
        open={suspending !== null}
        onOpenChange={(open) => !open && setSuspending(null)}
        title={`Suspender "${suspending?.name ?? ""}"`}
        description="Sus usuarios no podrán ingresar ni usar el sistema hasta que la reactives. Sus datos no se tocan."
        confirmLabel="Suspender"
        processingLabel="Suspendiendo…"
        destructive
        processing={setAccountStatus.isProcessing}
        onConfirm={() => {
          if (suspending) {
            void setAccountStatus
              .run({ id: suspending.id, plan_status: "suspended" })
              .then(() => setSuspending(null));
          }
        }}
      />

      <FormDialog
        open={creating}
        onOpenChange={setCreating}
        title="Nueva cuenta"
        description="Se crea con su propietario y 14 días de prueba."
        size="sm"
        submitLabel="Crear cuenta"
        processingLabel="Creando…"
        processing={create.isProcessing}
        slow={create.isSlow}
        onSubmit={() => void create.run()}
      >
        {(
          [
            ["name", "Nombre de la cuenta", "text"],
            ["owner_name", "Nombre del propietario", "text"],
            ["owner_email", "Correo del propietario", "email"],
            ["owner_password", "Contraseña inicial (mín. 10)", "password"],
          ] as const
        ).map(([key, label, type]) => (
          <div key={key} className="grid gap-2">
            <Label htmlFor={`new-${key}`}>{label}</Label>
            <Input
              id={`new-${key}`}
              type={type}
              value={form[key]}
              onChange={(event) => setForm({ ...form, [key]: event.target.value })}
              aria-invalid={fieldError(key) !== undefined}
            />
            {fieldError(key) && <p className="text-xs text-destructive">{fieldError(key)}</p>}
          </div>
        ))}
      </FormDialog>
    </div>
  );
}

export const Route = createFileRoute("/operador/")({
  beforeLoad: () => {
    if (!getOperatorToken()) throw redirect({ to: "/operador/login" });
  },
  component: OperatorPanelPage,
});
