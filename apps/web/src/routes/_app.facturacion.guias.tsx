import { createFileRoute } from "@tanstack/react-router";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus, Truck } from "lucide-react";
import { useState } from "react";

import { EmptyState } from "@/components/common/empty-state";
import { ErrorState } from "@/components/common/error-state";
import { StatusChip } from "@/components/common/status-chip";
import { DashboardLayout } from "@/components/dashboard/dashboard-layout";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { DispatchGuideDialog } from "@/features/fiscal/dispatch-guide-dialog";
import { useGuardedMutation } from "@/hooks/use-guarded-mutation";
import { api } from "@/lib/api";

type GuideStatus = "queued" | "pending" | "accepted" | "rejected" | "error";

/** Guía de remisión del listado. */
interface GuideRow {
  id: string;
  transfer_reason: string;
  transport_mode: "01" | "02";
  transfer_date: string;
  weight: string;
  weight_unit: string;
  recipient: string;
  arrival_address: string;
  series: string;
  number: string | null;
  status: GuideStatus;
  sunat_description: string | null;
  last_error: string | null;
}

const STATUS: Record<
  GuideStatus,
  { label: string; tone: "info" | "warning" | "success" | "danger" }
> = {
  queued: { label: "En cola", tone: "info" },
  pending: { label: "Enviada, esperando SUNAT", tone: "warning" },
  accepted: { label: "Aceptada", tone: "success" },
  rejected: { label: "Rechazada", tone: "danger" },
  error: { label: "Con error", tone: "danger" },
};

/** Nombre del destinatario guardado como JSON. */
function recipientName(raw: string): string {
  try {
    const parsed = JSON.parse(raw) as { name?: string };
    return parsed.name ?? "—";
  } catch {
    return "—";
  }
}

/** Bandeja de guías de remisión remitente, con alta sin venta asociada. */
function DispatchGuidesPage() {
  const queryClient = useQueryClient();
  const [creating, setCreating] = useState(false);
  const list = useQuery({
    queryKey: ["fiscal", "guides"],
    queryFn: async () => (await api<{ data: GuideRow[] }>("/fiscal/dispatch-guides")).data,
    refetchInterval: 30_000,
  });
  const branches = useQuery({
    queryKey: ["branches", "first"],
    queryFn: async () => (await api<{ data: { id: string }[] }>("/branches")).data,
  });

  const retry = useGuardedMutation({
    mutationFn: (id: string, { signal }) =>
      api(`/fiscal/dispatch-guides/${id}/retry`, { method: "POST", signal }),
    successMessage: "Reintento solicitado",
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ["fiscal"] }),
  });

  return (
    <DashboardLayout
      title="Guías de remisión"
      description="Traslado de mercadería. Desde Comprobantes puedes emitir la guía de una venta."
      actions={
        <Button size="sm" onClick={() => setCreating(true)} disabled={!branches.data?.[0]}>
          <Plus className="size-4" /> Nueva guía
        </Button>
      }
    >
      {list.isPending && <Skeleton className="h-24 w-full" />}
      {list.isError && <ErrorState onRetry={() => void list.refetch()} />}
      {list.data?.length === 0 && (
        <EmptyState
          icon={Truck}
          title="Aún no emites guías"
          description="Crea una guía para trasladar mercadería con o sin venta."
        />
      )}
      {list.data && list.data.length > 0 && (
        <Card className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Guía</TableHead>
                <TableHead>Destinatario</TableHead>
                <TableHead>Llegada</TableHead>
                <TableHead>Traslado</TableHead>
                <TableHead>Estado</TableHead>
                <TableHead>Detalle</TableHead>
                <TableHead className="w-28" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {list.data.map((row) => (
                <TableRow key={row.id}>
                  <TableCell className="font-medium tabular-nums">
                    {row.series}
                    {row.number ? `-${row.number}` : ""}
                  </TableCell>
                  <TableCell>{recipientName(row.recipient)}</TableCell>
                  <TableCell className="max-w-xs truncate text-sm">{row.arrival_address}</TableCell>
                  <TableCell className="text-sm tabular-nums">
                    {row.transfer_date.slice(0, 10)} · {Number(row.weight)} {row.weight_unit}
                  </TableCell>
                  <TableCell>
                    <StatusChip label={STATUS[row.status].label} tone={STATUS[row.status].tone} />
                  </TableCell>
                  <TableCell className="max-w-xs truncate text-xs text-muted-foreground">
                    {row.sunat_description ?? row.last_error ?? "—"}
                  </TableCell>
                  <TableCell className="text-right">
                    {(row.status === "queued" ||
                      row.status === "error" ||
                      row.status === "pending") && (
                      <Button
                        size="sm"
                        variant="outline"
                        disabled={retry.isProcessing}
                        onClick={() => void retry.run(row.id)}
                      >
                        Reintentar
                      </Button>
                    )}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Card>
      )}
      {creating && (
        <DispatchGuideDialog
          branchId={branches.data?.[0]?.id}
          onClose={() => setCreating(false)}
          onDone={() => {
            setCreating(false);
            void queryClient.invalidateQueries({ queryKey: ["fiscal"] });
          }}
        />
      )}
    </DashboardLayout>
  );
}

export const Route = createFileRoute("/_app/facturacion/guias")({
  component: DispatchGuidesPage,
});
