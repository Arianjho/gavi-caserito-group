import { createFileRoute } from "@tanstack/react-router";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Printer, Receipt } from "lucide-react";
import { useState } from "react";

import { NotifyButton } from "@/components/common/notify-button";
import { EmptyState } from "@/components/common/empty-state";
import { ErrorState } from "@/components/common/error-state";
import { FormDialog } from "@/components/common/form-dialog";
import { StatusChip } from "@/components/common/status-chip";
import { DashboardLayout } from "@/components/dashboard/dashboard-layout";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from "@/components/ui/dialog";
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
import { ReturnDialog } from "@/features/sales/return-dialog";
import { Ticket, type SaleDetail } from "@/features/sales/ticket";
import { useResourceList, type Company } from "@/features/organization/resources";
import { useGuardedMutation } from "@/hooks/use-guarded-mutation";
import { api } from "@/lib/api";
import { formatPen } from "@/lib/money";

/** Fila del listado de ventas. */
interface SaleRow {
  id: string;
  series: string;
  number: number;
  status: "confirmed" | "voided";
  total: string;
  issued_at: string;
}

/**
 * Listado de ventas con ticket imprimible y anulación con motivo.
 */
function SalesPage() {
  const queryClient = useQueryClient();
  const companies = useResourceList<Company>("companies");
  const list = useQuery({
    queryKey: ["sales"],
    queryFn: async () => (await api<{ data: SaleRow[] }>("/sales")).data,
  });

  const [viewId, setViewId] = useState<string | null>(null);
  const detail = useQuery({
    queryKey: ["sales", viewId],
    enabled: viewId !== null,
    queryFn: async () => (await api<{ data: SaleDetail }>(`/sales/${viewId}`)).data,
  });

  const [voidId, setVoidId] = useState<string | null>(null);
  const [reason, setReason] = useState("");
  const voidSale = useGuardedMutation({
    mutationFn: (_input: void, { signal }) =>
      api(`/sales/${voidId}/void`, { method: "POST", body: { reason }, signal }),
    successMessage: "Venta anulada y stock devuelto",
    onSuccess: () => {
      setVoidId(null);
      void queryClient.invalidateQueries({ queryKey: ["sales"] });
      void queryClient.invalidateQueries({ queryKey: ["inventory"] });
    },
  });

  const [returnId, setReturnId] = useState<string | null>(null);
  const [emitId, setEmitId] = useState<string | null>(null);
  const [cpeType, setCpeType] = useState<"01" | "03">("03");
  const emit = useGuardedMutation({
    mutationFn: (_input: void, { signal, idempotencyKey }) =>
      api(`/sales/${emitId}/emit`, {
        method: "POST",
        body: { cpe_type: cpeType },
        signal,
        idempotencyKey,
      }),
    successMessage: "Comprobante enviado a facturación",
    onSuccess: () => {
      setEmitId(null);
      void queryClient.invalidateQueries({ queryKey: ["sales"] });
      void queryClient.invalidateQueries({ queryKey: ["fiscal"] });
    },
  });

  const companyName = companies.data?.[0]?.trade_name ?? companies.data?.[0]?.legal_name ?? "";

  return (
    <DashboardLayout title="Ventas" description="Historial de ventas, tickets y anulaciones.">
      {list.isPending && <Skeleton className="h-24 w-full" />}
      {list.isError && <ErrorState onRetry={() => void list.refetch()} />}
      {list.data?.length === 0 && (
        <EmptyState
          icon={Receipt}
          title="Aún no hay ventas"
          description="Las ventas del punto de venta aparecerán aquí."
        />
      )}
      {list.data && list.data.length > 0 && (
        <Card className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Documento</TableHead>
                <TableHead>Fecha</TableHead>
                <TableHead className="text-right">Total</TableHead>
                <TableHead>Estado</TableHead>
                <TableHead className="w-40" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {list.data.map((sale) => (
                <TableRow key={sale.id}>
                  <TableCell className="font-medium tabular-nums">
                    {sale.series}-{String(sale.number).padStart(6, "0")}
                  </TableCell>
                  <TableCell>{new Date(sale.issued_at).toLocaleString("es-PE")}</TableCell>
                  <TableCell className="text-right tabular-nums">{formatPen(sale.total)}</TableCell>
                  <TableCell>
                    <StatusChip
                      label={sale.status === "confirmed" ? "Confirmada" : "Anulada"}
                      tone={sale.status === "confirmed" ? "success" : "danger"}
                    />
                  </TableCell>
                  <TableCell className="space-x-1 text-right">
                    <Button variant="ghost" size="sm" onClick={() => setViewId(sale.id)}>
                      <Printer /> Ticket
                    </Button>
                    {sale.status === "confirmed" && (
                      <NotifyButton path={`/sales/${sale.id}/notify`} />
                    )}
                    {sale.status === "confirmed" && (
                      <Button variant="ghost" size="sm" onClick={() => setReturnId(sale.id)}>
                        Devolver
                      </Button>
                    )}
                    {sale.status === "confirmed" && (
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => {
                          emit.reset();
                          setCpeType("03");
                          setEmitId(sale.id);
                        }}
                      >
                        Emitir comprobante
                      </Button>
                    )}
                    {sale.status === "confirmed" && (
                      <Button
                        variant="ghost"
                        size="sm"
                        className="text-destructive"
                        onClick={() => {
                          voidSale.reset();
                          setReason("");
                          setVoidId(sale.id);
                        }}
                      >
                        Anular
                      </Button>
                    )}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Card>
      )}

      <Dialog open={viewId !== null} onOpenChange={(open) => !open && setViewId(null)}>
        <DialogContent className="sm:max-w-sm">
          <DialogHeader>
            <DialogTitle>Ticket de venta</DialogTitle>
            <DialogDescription>Vista previa de impresión (80 mm).</DialogDescription>
          </DialogHeader>
          {detail.isPending && <Skeleton className="h-40 w-full" />}
          {detail.data && (
            <>
              <div className="max-h-[60vh] overflow-auto rounded-md border border-border bg-white">
                <Ticket sale={detail.data} companyName={companyName} />
              </div>
              <Button onClick={() => window.print()}>
                <Printer /> Imprimir ticket
              </Button>
            </>
          )}
        </DialogContent>
      </Dialog>

      {returnId && (
        <ReturnDialog
          saleId={returnId}
          onClose={() => setReturnId(null)}
          onDone={() => {
            setReturnId(null);
            void queryClient.invalidateQueries({ queryKey: ["sales"] });
            void queryClient.invalidateQueries({ queryKey: ["inventory"] });
            void queryClient.invalidateQueries({ queryKey: ["cash"] });
          }}
        />
      )}

      <FormDialog
        open={emitId !== null}
        onOpenChange={(open) => !open && setEmitId(null)}
        title="Emitir comprobante electrónico"
        description="Se envía a SUNAT mediante Gavi eFact. Si hay un corte, queda en cola y se reintenta solo."
        size="sm"
        submitLabel="Emitir"
        processingLabel="Enviando…"
        processing={emit.isProcessing}
        slow={emit.isSlow}
        onSubmit={() => void emit.run()}
      >
        <div className="flex gap-2">
          <Button
            type="button"
            size="sm"
            variant={cpeType === "03" ? "default" : "outline"}
            onClick={() => setCpeType("03")}
          >
            Boleta
          </Button>
          <Button
            type="button"
            size="sm"
            variant={cpeType === "01" ? "default" : "outline"}
            onClick={() => setCpeType("01")}
          >
            Factura
          </Button>
        </div>
        {cpeType === "01" && (
          <p className="text-xs text-muted-foreground">La factura requiere un cliente con RUC.</p>
        )}
      </FormDialog>

      <FormDialog
        open={voidId !== null}
        onOpenChange={(open) => !open && setVoidId(null)}
        title="Anular venta"
        description="La venta quedará anulada y el stock volverá al almacén. Esta acción no se puede deshacer."
        size="sm"
        submitLabel="Anular venta"
        processingLabel="Anulando…"
        processing={voidSale.isProcessing}
        slow={voidSale.isSlow}
        onSubmit={() => void voidSale.run()}
      >
        <div className="grid gap-2">
          <Label htmlFor="void-reason">Motivo de la anulación</Label>
          <Input
            id="void-reason"
            value={reason}
            onChange={(event) => setReason(event.target.value)}
            aria-invalid={voidSale.fieldErrors["reason"] !== undefined}
          />
          {voidSale.fieldErrors["reason"]?.[0] && (
            <p className="text-xs text-destructive">{voidSale.fieldErrors["reason"][0]}</p>
          )}
        </div>
      </FormDialog>
    </DashboardLayout>
  );
}

export const Route = createFileRoute("/_app/ventas/")({
  component: SalesPage,
});
