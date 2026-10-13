import { createFileRoute } from "@tanstack/react-router";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { HandCoins } from "lucide-react";
import { useState } from "react";

import { EmptyState } from "@/components/common/empty-state";
import { ErrorState } from "@/components/common/error-state";
import { FormDialog } from "@/components/common/form-dialog";
import { StatusChip } from "@/components/common/status-chip";
import { DashboardLayout } from "@/components/dashboard/dashboard-layout";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
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
import { formatPen } from "@/lib/money";

/** Documento con saldo pendiente. */
interface Payable {
  id: string;
  supplier_document: string | null;
  number: number;
  supplier_name: string | null;
  due_date: string | null;
  total: string;
  paid_total: string;
  balance: string;
  overdue: boolean;
}

/** Respuesta del listado de por cobrar. */
interface PayablesResponse {
  data: Payable[];
  summary: { balance_total: string; overdue_count: number };
}

const METHODS = [
  { value: "cash", label: "Efectivo" },
  { value: "yape", label: "Yape" },
  { value: "plin", label: "Plin" },
  { value: "card", label: "Tarjeta" },
  { value: "transfer", label: "Transferencia" },
] as const;

/**
 * Cuentas por cobrar: deudas de ventas a crédito, con registro de cobros parciales.
 */
function PayablesPage() {
  const queryClient = useQueryClient();
  const list = useQuery({
    queryKey: ["payables"],
    queryFn: () => api<PayablesResponse>("/payables"),
  });

  const [target, setTarget] = useState<Payable | null>(null);
  const [method, setMethod] = useState<(typeof METHODS)[number]["value"]>("cash");
  const [amount, setAmount] = useState("");

  const collect = useGuardedMutation({
    mutationFn: (_input: void, { signal, idempotencyKey }) =>
      api(`/purchases/${target?.id}/payments`, {
        method: "POST",
        signal,
        idempotencyKey,
        body: { method, amount: Number(amount) },
      }),
    successMessage: "Pago registrado",
    onSuccess: () => {
      setTarget(null);
      void queryClient.invalidateQueries({ queryKey: ["payables"] });
      void queryClient.invalidateQueries({ queryKey: ["purchases"] });
    },
  });

  const openCollect = (row: Payable) => {
    collect.reset();
    setMethod("cash");
    setAmount(Number(row.balance).toFixed(2));
    setTarget(row);
  };

  const amountError = collect.fieldErrors["amount"]?.[0];

  return (
    <DashboardLayout title="Por pagar" description="Lo que debes a tus proveedores.">
      {list.isPending && <Skeleton className="h-24 w-full" />}
      {list.isError && <ErrorState onRetry={() => void list.refetch()} />}
      {list.data && (
        <div className="mb-4 grid gap-3 sm:grid-cols-2">
          <Card>
            <CardContent className="p-4">
              <p className="text-xs text-muted-foreground">Total por pagar</p>
              <p className="text-2xl font-bold tabular-nums">
                {formatPen(list.data.summary.balance_total)}
              </p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <p className="text-xs text-muted-foreground">Documentos vencidos</p>
              <p className="text-2xl font-bold tabular-nums">{list.data.summary.overdue_count}</p>
            </CardContent>
          </Card>
        </div>
      )}
      {list.data?.data.length === 0 && (
        <EmptyState
          icon={HandCoins}
          title="No debes nada a proveedores"
          description="Las compras a crédito con saldo pendiente aparecerán aquí."
        />
      )}
      {list.data && list.data.data.length > 0 && (
        <Card className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Proveedor</TableHead>
                <TableHead>Documento</TableHead>
                <TableHead>Vence</TableHead>
                <TableHead className="text-right">Saldo</TableHead>
                <TableHead className="w-24" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {list.data.data.map((row) => (
                <TableRow key={row.id}>
                  <TableCell className="font-medium">{row.supplier_name ?? "—"}</TableCell>
                  <TableCell className="tabular-nums">
                    {row.supplier_document ?? `C-${String(row.number).padStart(5, "0")}`}
                  </TableCell>
                  <TableCell>
                    {row.due_date
                      ? new Date(`${row.due_date}T00:00:00`).toLocaleDateString("es-PE")
                      : "—"}
                    {row.overdue && <StatusChip label="Vencido" tone="danger" className="ml-2" />}
                  </TableCell>
                  <TableCell className="text-right font-medium tabular-nums">
                    {formatPen(row.balance)}
                  </TableCell>
                  <TableCell className="text-right">
                    <Button size="sm" onClick={() => openCollect(row)}>
                      Cobrar
                    </Button>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Card>
      )}

      <FormDialog
        open={target !== null}
        onOpenChange={(open) => !open && setTarget(null)}
        title="Registrar pago"
        description={
          target
            ? `${target.supplier_name ?? "Proveedor"} · saldo ${formatPen(target.balance)}`
            : undefined
        }
        size="sm"
        submitLabel="Registrar pago"
        processingLabel="Registrando…"
        processing={collect.isProcessing}
        slow={collect.isSlow}
        onSubmit={() => void collect.run()}
      >
        <div className="grid gap-2">
          <Label>Medio de pago</Label>
          <div className="flex flex-wrap gap-2">
            {METHODS.map((item) => (
              <Button
                key={item.value}
                type="button"
                size="sm"
                variant={method === item.value ? "default" : "outline"}
                onClick={() => setMethod(item.value)}
              >
                {item.label}
              </Button>
            ))}
          </div>
        </div>
        <div className="grid gap-2">
          <Label htmlFor="collect-amount">Monto</Label>
          <Input
            id="collect-amount"
            inputMode="decimal"
            value={amount}
            onChange={(event) => setAmount(event.target.value)}
            aria-invalid={amountError !== undefined}
          />
          {amountError && <p className="text-xs text-destructive">{amountError}</p>}
        </div>
      </FormDialog>
    </DashboardLayout>
  );
}

export const Route = createFileRoute("/_app/pagos/por-pagar")({
  component: PayablesPage,
});
