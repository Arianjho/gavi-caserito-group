import { createFileRoute } from "@tanstack/react-router";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Landmark } from "lucide-react";
import { useState } from "react";

import { EmptyState } from "@/components/common/empty-state";
import { ErrorState } from "@/components/common/error-state";
import { FormDialog } from "@/components/common/form-dialog";
import { DashboardLayout } from "@/components/dashboard/dashboard-layout";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { useResourceList, type Branch } from "@/features/organization/resources";
import { useGuardedMutation } from "@/hooks/use-guarded-mutation";
import { api } from "@/lib/api";
import { formatPen } from "@/lib/money";

/** Caja de cobro. */
interface Register {
  id: string;
  name: string;
  status: string;
}

/** Movimiento de caja chica. */
interface Movement {
  id: string;
  kind: "in" | "out";
  reason: string;
  amount: string;
}

/** Resumen de un turno. */
interface Session {
  id: string;
  status: "open" | "closed";
  opening_amount: string;
  expected_cash: string;
  movements_in: string;
  movements_out: string;
  collections_by_method: Record<string, string>;
  movements: Movement[];
}

const METHOD_LABEL: Record<string, string> = {
  cash: "Efectivo",
  yape: "Yape",
  plin: "Plin",
  card: "Tarjeta",
  transfer: "Transferencia",
  other: "Otro",
};

type Dialog = "register" | "open" | "movement" | "close" | null;

/**
 * Turno de caja: apertura con efectivo inicial, movimientos de caja chica,
 * resumen de cobros por medio y cierre con arqueo.
 */
function CashSessionPage() {
  const queryClient = useQueryClient();
  const current = useQuery({
    queryKey: ["cash-session"],
    queryFn: async () => (await api<{ data: Session | null }>("/cash-sessions/current")).data,
  });
  const registers = useResourceList<Register>("cash-registers");
  const branches = useResourceList<Branch>("branches");
  const refresh = () => {
    setDialog(null);
    void queryClient.invalidateQueries({ queryKey: ["cash-session"] });
    void queryClient.invalidateQueries({ queryKey: ["resource", "cash-registers"] });
  };

  const [dialog, setDialog] = useState<Dialog>(null);
  const [registerName, setRegisterName] = useState("Caja 1");
  const [branchId, setBranchId] = useState("");
  const [registerId, setRegisterId] = useState("");
  const [amount, setAmount] = useState("");
  const [kind, setKind] = useState<"in" | "out">("out");
  const [reason, setReason] = useState("");
  const [counted, setCounted] = useState("");
  const [notes, setNotes] = useState("");

  const activeRegisters = (registers.data ?? []).filter((r) => r.status === "active");
  const activeBranches = (branches.data ?? []).filter((b) => b.status === "active");

  const createRegister = useGuardedMutation({
    mutationFn: (_input: void, { signal, idempotencyKey }) =>
      api("/cash-registers", {
        method: "POST",
        signal,
        idempotencyKey,
        body: { name: registerName, branch_id: branchId || activeBranches[0]?.id },
      }),
    successMessage: "Caja creada",
    onSuccess: refresh,
  });

  const open = useGuardedMutation({
    mutationFn: (_input: void, { signal, idempotencyKey }) =>
      api("/cash-sessions", {
        method: "POST",
        signal,
        idempotencyKey,
        body: {
          cash_register_id: registerId || activeRegisters[0]?.id,
          opening_amount: Number(amount) || 0,
        },
      }),
    successMessage: "Turno abierto",
    onSuccess: refresh,
  });

  const movement = useGuardedMutation({
    mutationFn: (_input: void, { signal, idempotencyKey }) =>
      api(`/cash-sessions/${current.data?.id}/movements`, {
        method: "POST",
        signal,
        idempotencyKey,
        body: { kind, amount: Number(amount), reason },
      }),
    successMessage: "Movimiento registrado",
    onSuccess: refresh,
  });

  const close = useGuardedMutation({
    mutationFn: (_input: void, { signal, idempotencyKey }) =>
      api(`/cash-sessions/${current.data?.id}/close`, {
        method: "POST",
        signal,
        idempotencyKey,
        body: { counted_amount: Number(counted), notes: notes || null },
      }),
    successMessage: "Turno cerrado",
    onSuccess: refresh,
  });

  const show = (next: Dialog) => {
    createRegister.reset();
    open.reset();
    movement.reset();
    close.reset();
    setAmount("");
    setReason("");
    setCounted("");
    setNotes("");
    setDialog(next);
  };

  const session = current.data;
  const difference = session ? Number(counted || 0) - Number(session.expected_cash) : 0;

  return (
    <DashboardLayout title="Turno de caja" description="Apertura, movimientos y cierre con arqueo.">
      {current.isPending && <Skeleton className="h-32 w-full" />}
      {current.isError && <ErrorState onRetry={() => void current.refetch()} />}

      {current.data === null && (
        <EmptyState
          icon={Landmark}
          title="No tienes un turno abierto"
          description={
            activeRegisters.length === 0
              ? "Primero crea una caja para tu sucursal."
              : "Abre tu turno indicando el efectivo con el que inicias."
          }
          action={
            activeRegisters.length === 0 ? (
              <Button onClick={() => show("register")}>Crear caja</Button>
            ) : (
              <Button onClick={() => show("open")}>Abrir turno</Button>
            )
          }
        />
      )}

      {session && (
        <div className="space-y-4">
          <div className="grid gap-3 sm:grid-cols-3">
            <Card>
              <CardContent className="p-4">
                <p className="text-xs text-muted-foreground">Apertura</p>
                <p className="text-2xl font-bold tabular-nums">
                  {formatPen(session.opening_amount)}
                </p>
              </CardContent>
            </Card>
            <Card>
              <CardContent className="p-4">
                <p className="text-xs text-muted-foreground">Efectivo esperado</p>
                <p className="text-2xl font-bold tabular-nums">
                  {formatPen(session.expected_cash)}
                </p>
              </CardContent>
            </Card>
            <Card>
              <CardContent className="p-4">
                <p className="text-xs text-muted-foreground">Ingresos / egresos</p>
                <p className="text-2xl font-bold tabular-nums">
                  {formatPen(session.movements_in)} / {formatPen(session.movements_out)}
                </p>
              </CardContent>
            </Card>
          </div>

          <Card>
            <CardContent className="space-y-2 p-4">
              <h2 className="text-sm font-semibold">Cobros del turno por medio</h2>
              {Object.keys(session.collections_by_method).length === 0 && (
                <p className="text-sm text-muted-foreground">Aún no hay cobros en este turno.</p>
              )}
              {Object.entries(session.collections_by_method).map(([method, total]) => (
                <p key={method} className="flex justify-between text-sm">
                  <span>{METHOD_LABEL[method] ?? method}</span>
                  <span className="tabular-nums">{formatPen(total)}</span>
                </p>
              ))}
            </CardContent>
          </Card>

          <Card>
            <CardContent className="space-y-2 p-4">
              <h2 className="text-sm font-semibold">Movimientos de caja chica</h2>
              {session.movements.length === 0 && (
                <p className="text-sm text-muted-foreground">Sin movimientos.</p>
              )}
              {session.movements.map((item) => (
                <p key={item.id} className="flex justify-between text-sm">
                  <span>
                    {item.kind === "in" ? "Ingreso" : "Egreso"} · {item.reason}
                  </span>
                  <span className="tabular-nums">
                    {item.kind === "in" ? "+" : "−"} {formatPen(item.amount)}
                  </span>
                </p>
              ))}
            </CardContent>
          </Card>

          <div className="flex gap-2">
            <Button variant="outline" onClick={() => show("movement")}>
              Registrar ingreso o egreso
            </Button>
            <Button onClick={() => show("close")}>Cerrar turno</Button>
          </div>
        </div>
      )}

      <FormDialog
        open={dialog === "register"}
        onOpenChange={(value) => !value && setDialog(null)}
        title="Crear caja"
        size="sm"
        submitLabel="Crear caja"
        processingLabel="Creando…"
        processing={createRegister.isProcessing}
        slow={createRegister.isSlow}
        onSubmit={() => void createRegister.run()}
      >
        <div className="grid gap-2">
          <Label htmlFor="register-name">Nombre</Label>
          <Input
            id="register-name"
            value={registerName}
            onChange={(event) => setRegisterName(event.target.value)}
          />
        </div>
        <div className="grid gap-2">
          <Label htmlFor="register-branch">Sucursal</Label>
          <select
            id="register-branch"
            value={branchId || activeBranches[0]?.id || ""}
            onChange={(event) => setBranchId(event.target.value)}
            className="h-9 rounded-md border border-input bg-background px-3 text-sm"
          >
            {activeBranches.map((branch) => (
              <option key={branch.id} value={branch.id}>
                {branch.name}
              </option>
            ))}
          </select>
        </div>
      </FormDialog>

      <FormDialog
        open={dialog === "open"}
        onOpenChange={(value) => !value && setDialog(null)}
        title="Abrir turno"
        size="sm"
        submitLabel="Abrir turno"
        processingLabel="Abriendo…"
        processing={open.isProcessing}
        slow={open.isSlow}
        onSubmit={() => void open.run()}
      >
        <div className="grid gap-2">
          <Label htmlFor="open-register">Caja</Label>
          <select
            id="open-register"
            value={registerId || activeRegisters[0]?.id || ""}
            onChange={(event) => setRegisterId(event.target.value)}
            className="h-9 rounded-md border border-input bg-background px-3 text-sm"
          >
            {activeRegisters.map((register) => (
              <option key={register.id} value={register.id}>
                {register.name}
              </option>
            ))}
          </select>
        </div>
        <div className="grid gap-2">
          <Label htmlFor="open-amount">Efectivo inicial</Label>
          <Input
            id="open-amount"
            inputMode="decimal"
            value={amount}
            onChange={(event) => setAmount(event.target.value)}
          />
        </div>
      </FormDialog>

      <FormDialog
        open={dialog === "movement"}
        onOpenChange={(value) => !value && setDialog(null)}
        title="Movimiento de caja"
        size="sm"
        submitLabel="Registrar"
        processingLabel="Registrando…"
        processing={movement.isProcessing}
        slow={movement.isSlow}
        onSubmit={() => void movement.run()}
      >
        <div className="flex gap-2">
          <Button
            type="button"
            size="sm"
            variant={kind === "out" ? "default" : "outline"}
            onClick={() => setKind("out")}
          >
            Egreso
          </Button>
          <Button
            type="button"
            size="sm"
            variant={kind === "in" ? "default" : "outline"}
            onClick={() => setKind("in")}
          >
            Ingreso
          </Button>
        </div>
        <div className="grid gap-2">
          <Label htmlFor="mov-amount">Monto</Label>
          <Input
            id="mov-amount"
            inputMode="decimal"
            value={amount}
            onChange={(event) => setAmount(event.target.value)}
          />
        </div>
        <div className="grid gap-2">
          <Label htmlFor="mov-reason">Motivo</Label>
          <Input
            id="mov-reason"
            value={reason}
            onChange={(event) => setReason(event.target.value)}
          />
        </div>
      </FormDialog>

      <FormDialog
        open={dialog === "close"}
        onOpenChange={(value) => !value && setDialog(null)}
        title="Cerrar turno"
        description={session ? `Efectivo esperado: ${formatPen(session.expected_cash)}` : undefined}
        size="sm"
        submitLabel="Cerrar turno"
        processingLabel="Cerrando…"
        processing={close.isProcessing}
        slow={close.isSlow}
        onSubmit={() => void close.run()}
      >
        <div className="grid gap-2">
          <Label htmlFor="close-counted">Efectivo contado</Label>
          <Input
            id="close-counted"
            inputMode="decimal"
            value={counted}
            onChange={(event) => setCounted(event.target.value)}
          />
          {counted !== "" && (
            <p className="text-xs text-muted-foreground">
              Diferencia: {difference === 0 ? "cuadra" : formatPen(difference)}
            </p>
          )}
        </div>
        <div className="grid gap-2">
          <Label htmlFor="close-notes">Observaciones</Label>
          <Input
            id="close-notes"
            value={notes}
            onChange={(event) => setNotes(event.target.value)}
          />
        </div>
      </FormDialog>
    </DashboardLayout>
  );
}

export const Route = createFileRoute("/_app/caja/turno")({
  component: CashSessionPage,
});
