import { createFileRoute, Link } from "@tanstack/react-router";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { ClipboardList, Plus } from "lucide-react";
import { useState } from "react";

import { NotifyButton } from "@/components/common/notify-button";
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
import { formatPen, roundMoney } from "@/lib/money";

type OrderStatus = "confirmed" | "in_dispatch" | "ready" | "delivered" | "cancelled";

/** Fila del listado de pedidos. */
interface OrderRow {
  id: string;
  number: number;
  status: OrderStatus;
  total: string;
  prepaid_total: string;
  party_name: string;
  created_at: string;
}

/** Línea de un pedido en el detalle. */
interface OrderLine {
  id: string;
  description: string;
  qty_ordered: string;
  qty_delivered: string;
  unit_price: string;
}

/** Detalle de un pedido. */
interface OrderDetail {
  id: string;
  number: number;
  total: string;
  prepaid_total: string;
  prepaid_applied: string;
  lines: OrderLine[];
}

const STATUS: Record<
  OrderStatus,
  { label: string; tone: "info" | "warning" | "success" | "neutral" | "danger" }
> = {
  confirmed: { label: "Confirmado", tone: "info" },
  in_dispatch: { label: "En despacho", tone: "warning" },
  ready: { label: "Listo", tone: "success" },
  delivered: { label: "Entregado", tone: "neutral" },
  cancelled: { label: "Cancelado", tone: "danger" },
};

const NEXT_LABEL: Partial<Record<OrderStatus, string>> = {
  confirmed: "Pasar a despacho",
  in_dispatch: "Marcar listo",
};

/**
 * Listado de pedidos con avance de estado, entrega y cancelación.
 */
function OrdersPage() {
  const queryClient = useQueryClient();
  const list = useQuery({
    queryKey: ["orders"],
    queryFn: async () => (await api<{ data: OrderRow[] }>("/orders")).data,
  });
  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ["orders"] });
    void queryClient.invalidateQueries({ queryKey: ["inventory"] });
    void queryClient.invalidateQueries({ queryKey: ["sales"] });
  };

  const advance = useGuardedMutation({
    mutationFn: (id: string, { signal }) =>
      api(`/orders/${id}/advance`, { method: "POST", signal }),
    successMessage: "Pedido actualizado",
    onSuccess: refresh,
  });

  // Entrega
  const [deliverId, setDeliverId] = useState<string | null>(null);
  const detail = useQuery({
    queryKey: ["orders", deliverId],
    enabled: deliverId !== null,
    queryFn: async () => (await api<{ data: OrderDetail }>(`/orders/${deliverId}`)).data,
  });
  const [qtys, setQtys] = useState<Record<string, string>>({});
  const [payMethod, setPayMethod] = useState("cash");

  const lines = detail.data?.lines ?? [];
  const toDeliver = lines
    .map((line) => ({ line, qty: Number(qtys[line.id] ?? 0) }))
    .filter((item) => item.qty > 0);
  const deliveryTotal = roundMoney(
    toDeliver.reduce((sum, item) => sum + item.qty * Number(item.line.unit_price), 0),
  );
  const advanceAvailable = detail.data
    ? Number(detail.data.prepaid_total) - Number(detail.data.prepaid_applied)
    : 0;
  const advanceApplied = roundMoney(Math.min(advanceAvailable, deliveryTotal));
  const toCollect = roundMoney(deliveryTotal - advanceApplied);

  const deliver = useGuardedMutation({
    mutationFn: (_input: void, { signal, idempotencyKey }) =>
      api(`/orders/${deliverId}/deliver`, {
        method: "POST",
        signal,
        idempotencyKey,
        body: {
          deliveries: toDeliver.map((item) => ({ line_id: item.line.id, qty: item.qty })),
          payments: toCollect > 0 ? [{ method: payMethod, amount: toCollect }] : [],
        },
      }),
    successMessage: "Entrega registrada y venta generada",
    onSuccess: () => {
      setDeliverId(null);
      refresh();
    },
  });

  const openDeliver = (id: string) => {
    deliver.reset();
    setQtys({});
    setPayMethod("cash");
    setDeliverId(id);
  };

  // Cancelación
  const [cancelId, setCancelId] = useState<string | null>(null);
  const [reason, setReason] = useState("");
  const cancel = useGuardedMutation({
    mutationFn: (_input: void, { signal }) =>
      api(`/orders/${cancelId}/cancel`, { method: "POST", body: { reason }, signal }),
    successMessage: "Pedido cancelado y stock liberado",
    onSuccess: () => {
      setCancelId(null);
      refresh();
    },
  });

  return (
    <DashboardLayout
      title="Pedidos"
      description="Lo que tus clientes encargaron, con stock reservado y entregas parciales."
      actions={
        <Button asChild>
          <Link to="/ventas/pedidos/nuevo">
            <Plus /> Nuevo pedido
          </Link>
        </Button>
      }
    >
      {list.isPending && <Skeleton className="h-24 w-full" />}
      {list.isError && <ErrorState onRetry={() => void list.refetch()} />}
      {list.data?.length === 0 && (
        <EmptyState
          icon={ClipboardList}
          title="Aún no hay pedidos"
          description="Crea un pedido para reservar stock y entregarlo cuando el cliente lo recoja."
          action={
            <Button asChild>
              <Link to="/ventas/pedidos/nuevo">Nuevo pedido</Link>
            </Button>
          }
        />
      )}
      {list.data && list.data.length > 0 && (
        <Card className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Pedido</TableHead>
                <TableHead>Cliente</TableHead>
                <TableHead>Estado</TableHead>
                <TableHead className="text-right">Total</TableHead>
                <TableHead className="text-right">Anticipo</TableHead>
                <TableHead className="w-64" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {list.data.map((order) => {
                const open = order.status !== "delivered" && order.status !== "cancelled";
                const nextLabel = NEXT_LABEL[order.status];
                return (
                  <TableRow key={order.id}>
                    <TableCell className="font-medium tabular-nums">
                      #{String(order.number).padStart(5, "0")}
                    </TableCell>
                    <TableCell>{order.party_name}</TableCell>
                    <TableCell>
                      <StatusChip
                        label={STATUS[order.status].label}
                        tone={STATUS[order.status].tone}
                      />
                    </TableCell>
                    <TableCell className="text-right tabular-nums">
                      {formatPen(order.total)}
                    </TableCell>
                    <TableCell className="text-right tabular-nums">
                      {formatPen(order.prepaid_total)}
                    </TableCell>
                    <TableCell className="space-x-1 text-right">
                      <NotifyButton path={`/orders/${order.id}/notify`} />
                      {nextLabel && (
                        <Button
                          variant="outline"
                          size="sm"
                          disabled={advance.isProcessing}
                          onClick={() => void advance.run(order.id)}
                        >
                          {nextLabel}
                        </Button>
                      )}
                      {open && (
                        <Button variant="ghost" size="sm" asChild>
                          <Link to="/ventas/pedidos/nuevo" search={{ edit: order.id }}>
                            Editar
                          </Link>
                        </Button>
                      )}
                      {open && (
                        <Button size="sm" onClick={() => openDeliver(order.id)}>
                          Entregar
                        </Button>
                      )}
                      {open && (
                        <Button
                          variant="ghost"
                          size="sm"
                          className="text-destructive"
                          onClick={() => {
                            cancel.reset();
                            setReason("");
                            setCancelId(order.id);
                          }}
                        >
                          Cancelar
                        </Button>
                      )}
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
        </Card>
      )}

      <FormDialog
        open={deliverId !== null}
        onOpenChange={(open) => !open && setDeliverId(null)}
        title={
          detail.data
            ? `Entregar pedido #${String(detail.data.number).padStart(5, "0")}`
            : "Entregar pedido"
        }
        description="Indica cuánto se entrega ahora. Se genera una venta y se aplica el anticipo."
        submitLabel={`Entregar y cobrar ${formatPen(toCollect)}`}
        processingLabel="Registrando entrega…"
        processing={deliver.isProcessing}
        slow={deliver.isSlow}
        onSubmit={() => {
          if (toDeliver.length > 0) void deliver.run();
        }}
      >
        {detail.isPending && <Skeleton className="h-20 w-full" />}
        {lines.map((line) => {
          const pending = Number(line.qty_ordered) - Number(line.qty_delivered);
          return (
            <div key={line.id} className="grid grid-cols-[1fr_6rem] items-center gap-3">
              <div className="min-w-0">
                <p className="truncate text-sm font-medium">{line.description}</p>
                <p className="text-xs text-muted-foreground">
                  Pendiente: {pending} · {formatPen(line.unit_price)}
                </p>
              </div>
              <Input
                aria-label={`Cantidad a entregar de ${line.description}`}
                inputMode="decimal"
                disabled={pending <= 0}
                value={qtys[line.id] ?? ""}
                placeholder="0"
                onChange={(event) => setQtys({ ...qtys, [line.id]: event.target.value })}
              />
            </div>
          );
        })}
        <div className="space-y-1 rounded-md bg-muted px-3 py-2 text-sm">
          <p className="flex justify-between">
            <span>Total de la entrega</span>
            <span className="tabular-nums">{formatPen(deliveryTotal)}</span>
          </p>
          <p className="flex justify-between text-muted-foreground">
            <span>Anticipo aplicado</span>
            <span className="tabular-nums">- {formatPen(advanceApplied)}</span>
          </p>
          <p className="flex justify-between font-semibold">
            <span>A cobrar ahora</span>
            <span className="tabular-nums">{formatPen(toCollect)}</span>
          </p>
        </div>
        {toCollect > 0 && (
          <div className="grid gap-2">
            <Label htmlFor="deliver-method">Medio de pago</Label>
            <select
              id="deliver-method"
              value={payMethod}
              onChange={(event) => setPayMethod(event.target.value)}
              className="h-9 rounded-md border border-input bg-background px-3 text-sm"
            >
              <option value="cash">Efectivo</option>
              <option value="yape">Yape</option>
              <option value="plin">Plin</option>
              <option value="card">Tarjeta</option>
              <option value="transfer">Transferencia</option>
            </select>
          </div>
        )}
      </FormDialog>

      <FormDialog
        open={cancelId !== null}
        onOpenChange={(open) => !open && setCancelId(null)}
        title="Cancelar pedido"
        description="Se libera el stock reservado. Los anticipos recibidos deberán devolverse o aplicarse a otra venta."
        size="sm"
        submitLabel="Cancelar pedido"
        processingLabel="Cancelando…"
        processing={cancel.isProcessing}
        slow={cancel.isSlow}
        onSubmit={() => void cancel.run()}
      >
        <div className="grid gap-2">
          <Label htmlFor="cancel-reason">Motivo</Label>
          <Input
            id="cancel-reason"
            value={reason}
            onChange={(event) => setReason(event.target.value)}
            aria-invalid={cancel.fieldErrors["reason"] !== undefined}
          />
          {cancel.fieldErrors["reason"]?.[0] && (
            <p className="text-xs text-destructive">{cancel.fieldErrors["reason"][0]}</p>
          )}
        </div>
      </FormDialog>
    </DashboardLayout>
  );
}

export const Route = createFileRoute("/_app/ventas/pedidos/")({
  component: OrdersPage,
});
