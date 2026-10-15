import { createFileRoute } from "@tanstack/react-router";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { FileCheck2 } from "lucide-react";
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
import { CreditNoteDialog } from "@/features/fiscal/credit-note-dialog";
import { DispatchGuideDialog } from "@/features/fiscal/dispatch-guide-dialog";
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

type FiscalStatus = "queued" | "pending" | "accepted" | "rejected" | "error" | "voided";

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
  sales_document_id: string;
  void_status: "pending" | "accepted" | "rejected" | null;
  void_error: string | null;
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
  voided: { label: "Dado de baja", tone: "neutral" },
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

  const [noteFor, setNoteFor] = useState<FiscalRow | null>(null);
  const [guideFor, setGuideFor] = useState<FiscalRow | null>(null);
  const [voidFor, setVoidFor] = useState<FiscalRow | null>(null);
  const [voidReason, setVoidReason] = useState("");
  const label = (row: FiscalRow) => `${row.series}${row.number ? `-${row.number}` : ""}`;

  const voidDoc = useGuardedMutation({
    mutationFn: (_input: void, { signal, idempotencyKey }) =>
      api(`/fiscal/documents/${voidFor?.id}/void`, {
        method: "POST",
        signal,
        idempotencyKey,
        body: { reason: voidReason },
      }),
    successMessage: "Baja solicitada a SUNAT",
    onSuccess: () => {
      setVoidFor(null);
      setVoidReason("");
      void queryClient.invalidateQueries({ queryKey: ["fiscal"] });
    },
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
                    {row.void_status === "pending" && (
                      <StatusChip label="Baja en curso" tone="warning" />
                    )}
                  </TableCell>
                  <TableCell className="max-w-xs truncate text-xs text-muted-foreground">
                    {row.void_error ?? row.sunat_description ?? row.last_error ?? "—"}
                  </TableCell>
                  <TableCell className="space-x-1 whitespace-nowrap text-right">
                    {(row.status === "queued" ||
                      row.status === "error" ||
                      row.status === "pending" ||
                      row.void_status === "pending") && (
                      <Button
                        size="sm"
                        variant="outline"
                        disabled={retry.isProcessing}
                        onClick={() => void retry.run(row.id)}
                      >
                        Reintentar
                      </Button>
                    )}
                    {row.status === "accepted" && row.void_status !== "pending" && (
                      <>
                        <Button size="sm" variant="outline" onClick={() => setNoteFor(row)}>
                          Nota de crédito
                        </Button>
                        <Button size="sm" variant="outline" onClick={() => setGuideFor(row)}>
                          Guía
                        </Button>
                        <Button size="sm" variant="ghost" onClick={() => setVoidFor(row)}>
                          Dar de baja
                        </Button>
                      </>
                    )}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Card>
      )}
      {noteFor && (
        <CreditNoteDialog
          documentId={noteFor.id}
          saleId={noteFor.sales_document_id}
          label={label(noteFor)}
          onClose={() => setNoteFor(null)}
          onDone={() => {
            setNoteFor(null);
            void queryClient.invalidateQueries({ queryKey: ["fiscal"] });
          }}
        />
      )}
      {guideFor && (
        <DispatchGuideDialog
          saleId={guideFor.sales_document_id}
          onClose={() => setGuideFor(null)}
          onDone={() => {
            setGuideFor(null);
            void queryClient.invalidateQueries({ queryKey: ["fiscal"] });
          }}
        />
      )}
      {voidFor && (
        <FormDialog
          open
          onOpenChange={(open) => !open && setVoidFor(null)}
          title={`Dar de baja ${label(voidFor)}`}
          description="Se comunica a SUNAT (hasta 7 días de emitido y sin notas de crédito). Al aceptarse, la venta se anula y el stock vuelve al almacén."
          submitLabel="Solicitar baja"
          processingLabel="Enviando…"
          processing={voidDoc.isProcessing}
          slow={voidDoc.isSlow}
          onSubmit={() => (voidReason.trim() !== "" ? void voidDoc.run() : undefined)}
        >
          <div className="grid gap-2">
            <Label htmlFor="void-reason">Motivo</Label>
            <Input
              id="void-reason"
              maxLength={255}
              value={voidReason}
              onChange={(event) => setVoidReason(event.target.value)}
              aria-invalid={voidDoc.fieldErrors["reason"] !== undefined}
            />
            {voidDoc.fieldErrors["reason"]?.[0] && (
              <p className="text-xs text-destructive">{voidDoc.fieldErrors["reason"][0]}</p>
            )}
          </div>
        </FormDialog>
      )}
    </DashboardLayout>
  );
}

export const Route = createFileRoute("/_app/facturacion/comprobantes")({
  component: FiscalDocumentsPage,
});
