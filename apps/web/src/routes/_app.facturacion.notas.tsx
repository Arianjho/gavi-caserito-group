import { createFileRoute } from "@tanstack/react-router";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { FileMinus2 } from "lucide-react";

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

type NoteStatus = "queued" | "pending" | "accepted" | "rejected" | "error";

/** Nota de crédito del listado. */
interface NoteRow {
  id: string;
  reason_code: string;
  reason_text: string;
  total: string;
  series: string;
  number: string | null;
  status: NoteStatus;
  sunat_description: string | null;
  last_error: string | null;
  cpe_type: "01" | "03";
  doc_series: string;
  doc_number: string | null;
}

const STATUS: Record<
  NoteStatus,
  { label: string; tone: "info" | "warning" | "success" | "danger" }
> = {
  queued: { label: "En cola", tone: "info" },
  pending: { label: "Enviada, esperando SUNAT", tone: "warning" },
  accepted: { label: "Aceptada", tone: "success" },
  rejected: { label: "Rechazada", tone: "danger" },
  error: { label: "Con error", tone: "danger" },
};

/** Bandeja de notas de crédito electrónicas con su estado SUNAT. */
function CreditNotesPage() {
  const queryClient = useQueryClient();
  const list = useQuery({
    queryKey: ["fiscal", "credit-notes"],
    queryFn: async () => (await api<{ data: NoteRow[] }>("/fiscal/credit-notes")).data,
    refetchInterval: 30_000,
  });

  const retry = useGuardedMutation({
    mutationFn: (id: string, { signal }) =>
      api(`/fiscal/credit-notes/${id}/retry`, { method: "POST", signal }),
    successMessage: "Reintento solicitado",
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ["fiscal"] }),
  });

  return (
    <DashboardLayout
      title="Notas de crédito"
      description="Correcciones a boletas y facturas ya aceptadas. Se emiten desde Comprobantes."
    >
      {list.isPending && <Skeleton className="h-24 w-full" />}
      {list.isError && <ErrorState onRetry={() => void list.refetch()} />}
      {list.data?.length === 0 && (
        <EmptyState
          icon={FileMinus2}
          title="Aún no emites notas de crédito"
          description="En Comprobantes, elige un comprobante aceptado y usa «Nota de crédito»."
        />
      )}
      {list.data && list.data.length > 0 && (
        <Card className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Nota</TableHead>
                <TableHead>Modifica a</TableHead>
                <TableHead>Motivo</TableHead>
                <TableHead className="text-right">Importe</TableHead>
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
                  <TableCell className="tabular-nums">
                    {row.cpe_type === "01" ? "Factura" : "Boleta"} {row.doc_series}
                    {row.doc_number ? `-${row.doc_number}` : ""}
                  </TableCell>
                  <TableCell className="max-w-xs truncate text-sm">
                    {row.reason_code} · {row.reason_text}
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

export const Route = createFileRoute("/_app/facturacion/notas")({
  component: CreditNotesPage,
});
