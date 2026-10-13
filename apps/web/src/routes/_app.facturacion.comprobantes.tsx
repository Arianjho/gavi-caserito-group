import { createFileRoute } from "@tanstack/react-router";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { FileCheck2 } from "lucide-react";

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
import { useGuardedMutation } from "@/hooks/use-guarded-mutation";
import { api } from "@/lib/api";
import { formatPen } from "@/lib/money";

type FiscalStatus = "queued" | "pending" | "accepted" | "rejected" | "error";

/** Comprobante electrónico del listado. */
interface FiscalRow {
  id: string;
  cpe_type: "01" | "03";
  series: string;
  number: string | null;
  status: FiscalStatus;
  sunat_code: string | null;
  sunat_description: string | null;
  last_error: string | null;
  attempts: number;
  total: string;
  sale_series: string;
  sale_number: number;
}

const STATUS: Record<
  FiscalStatus,
  { label: string; tone: "info" | "warning" | "success" | "neutral" | "danger" }
> = {
  queued: { label: "En cola", tone: "info" },
  pending: { label: "Enviado, esperando SUNAT", tone: "warning" },
  accepted: { label: "Aceptado", tone: "success" },
  rejected: { label: "Rechazado", tone: "danger" },
  error: { label: "Con error", tone: "danger" },
};

/**
 * Bandeja de comprobantes electrónicos: estado SUNAT, motivo de rechazo o
 * error técnico y reintento manual.
 */
function FiscalDocumentsPage() {
  const queryClient = useQueryClient();
  const list = useQuery({
    queryKey: ["fiscal", "documents"],
    queryFn: async () => (await api<{ data: FiscalRow[] }>("/fiscal/documents")).data,
    refetchInterval: 30_000,
  });

  const retry = useGuardedMutation({
    mutationFn: (id: string, { signal }) =>
      api(`/fiscal/documents/${id}/retry`, { method: "POST", signal }),
    successMessage: "Reintento solicitado",
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ["fiscal"] }),
  });

  return (
    <DashboardLayout
      title="Comprobantes electrónicos"
      description="Boletas y facturas enviadas a SUNAT. Un corte no bloquea tus ventas."
    >
      {list.isPending && <Skeleton className="h-24 w-full" />}
      {list.isError && <ErrorState onRetry={() => void list.refetch()} />}
      {list.data?.length === 0 && (
        <EmptyState
          icon={FileCheck2}
          title="Aún no emites comprobantes"
          description="Desde Ventas puedes emitir una boleta o factura electrónica."
        />
      )}
      {list.data && list.data.length > 0 && (
        <Card className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Comprobante</TableHead>
                <TableHead>Venta</TableHead>
                <TableHead className="text-right">Total</TableHead>
                <TableHead>Estado</TableHead>
                <TableHead>Detalle</TableHead>
                <TableHead className="w-28" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {list.data.map((row) => (
                <TableRow key={row.id}>
                  <TableCell className="font-medium tabular-nums">
                    {row.cpe_type === "01" ? "Factura" : "Boleta"} {row.series}
                    {row.number ? `-${row.number}` : ""}
                  </TableCell>
                  <TableCell className="tabular-nums">
                    {row.sale_series}-{String(row.sale_number).padStart(6, "0")}
                  </TableCell>
                  <TableCell className="text-right tabular-nums">{formatPen(row.total)}</TableCell>
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
    </DashboardLayout>
  );
}

export const Route = createFileRoute("/_app/facturacion/comprobantes")({
  component: FiscalDocumentsPage,
});
