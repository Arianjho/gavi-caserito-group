import { createFileRoute } from "@tanstack/react-router";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { PackageCheck } from "lucide-react";

import { ActionButton } from "@/components/common/action-button";
import { EmptyState } from "@/components/common/empty-state";
import { ErrorState } from "@/components/common/error-state";
import { StatusChip } from "@/components/common/status-chip";
import { DashboardLayout } from "@/components/dashboard/dashboard-layout";
import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { useGuardedMutation } from "@/hooks/use-guarded-mutation";
import { api } from "@/lib/api";

/** Pedido en preparación con sus líneas. */
interface PickOrder {
  id: string;
  number: number;
  status: "confirmed" | "in_dispatch";
  party_name: string;
  delivery_type: "pickup" | "shipment" | "delivery";
  requested_date: string | null;
  lines: { description: string; qty_ordered: string; qty_delivered: string }[];
}

const DELIVERY = { pickup: "Recojo", shipment: "Envío a agencia", delivery: "Delivery" } as const;

/**
 * Cola de armado: pedidos que faltan preparar, con lo que hay que juntar.
 * El orden es por fecha solicitada y luego por antigüedad.
 */
function PickingPage() {
  const queryClient = useQueryClient();
  const list = useQuery({
    queryKey: ["orders", "picking"],
    queryFn: async () =>
      (await api<{ data: PickOrder[] }>("/orders?status=confirmed,in_dispatch&with_lines=1")).data,
  });

  const advance = useGuardedMutation({
    mutationFn: (id: string, { signal }) =>
      api(`/orders/${id}/advance`, { method: "POST", signal }),
    successMessage: "Pedido avanzado",
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["orders"] });
    },
  });

  const rows = [...(list.data ?? [])].sort((a, b) => {
    const da = a.requested_date ?? "9999-12-31";
    const db = b.requested_date ?? "9999-12-31";
    return da === db ? a.number - b.number : da.localeCompare(db);
  });

  return (
    <DashboardLayout title="Por armar" description="Pedidos pendientes de preparar.">
      {list.isPending && <Skeleton className="h-24 w-full" />}
      {list.isError && <ErrorState onRetry={() => void list.refetch()} />}
      {list.data?.length === 0 && (
        <EmptyState
          icon={PackageCheck}
          title="Nada por armar"
          description="Los pedidos confirmados aparecerán aquí para prepararlos."
        />
      )}
      <div className="grid gap-3 md:grid-cols-2">
        {rows.map((order) => (
          <Card key={order.id}>
            <CardContent className="space-y-3 p-4">
              <div className="flex items-start justify-between gap-2">
                <div>
                  <p className="font-medium">
                    #{String(order.number).padStart(5, "0")} · {order.party_name}
                  </p>
                  <p className="text-xs text-muted-foreground">
                    {DELIVERY[order.delivery_type]}
                    {order.requested_date &&
                      ` · para el ${new Date(`${order.requested_date}T00:00:00`).toLocaleDateString("es-PE")}`}
                  </p>
                </div>
                <StatusChip
                  label={order.status === "confirmed" ? "Confirmado" : "En preparación"}
                  tone={order.status === "confirmed" ? "info" : "warning"}
                />
              </div>
              <ul className="divide-y divide-border text-sm">
                {order.lines.map((line, index) => (
                  <li key={index} className="flex justify-between gap-2 py-1">
                    <span>{line.description}</span>
                    <span className="tabular-nums">
                      {Number(line.qty_ordered) - Number(line.qty_delivered)}
                    </span>
                  </li>
                ))}
              </ul>
              <ActionButton
                size="sm"
                processing={advance.isProcessing}
                processingLabel="Avanzando…"
                onClick={() => void advance.run(order.id)}
              >
                {order.status === "confirmed" ? "Empezar a armar" : "Marcar listo"}
              </ActionButton>
            </CardContent>
          </Card>
        ))}
      </div>
    </DashboardLayout>
  );
}

export const Route = createFileRoute("/_app/envios/por-armar")({
  component: PickingPage,
});
