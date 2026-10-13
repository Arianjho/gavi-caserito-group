import { createFileRoute, Link } from "@tanstack/react-router";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { PackagePlus, Plus } from "lucide-react";
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
import { Skeleton } from "@/components/ui/skeleton";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { PurchaseReturnDialog } from "@/features/purchases/return-dialog";
import { useGuardedMutation } from "@/hooks/use-guarded-mutation";
import { api } from "@/lib/api";
import { formatPen } from "@/lib/money";

/** Fila del listado de compras. */
interface PurchaseRow {
  id: string;
  number: number;
  supplier_document: string | null;
  issued_date: string;
  total: string;
  balance: string;
  status: "confirmed" | "voided";
  payment_condition: "cash" | "credit";
  supplier_name: string;
}

/**
 * Listado de compras con anulación (solo si no tienen pagos).
 */
function PurchasesPage() {
  const queryClient = useQueryClient();
  const list = useQuery({
    queryKey: ["purchases"],
    queryFn: async () => (await api<{ data: PurchaseRow[] }>("/purchases")).data,
  });

  const [returnId, setReturnId] = useState<string | null>(null);
  const [voidId, setVoidId] = useState<string | null>(null);
  const [reason, setReason] = useState("");
  const voidPurchase = useGuardedMutation({
    mutationFn: (_input: void, { signal }) =>
      api(`/purchases/${voidId}/void`, { method: "POST", body: { reason }, signal }),
    successMessage: "Compra anulada y stock revertido",
    onSuccess: () => {
      setVoidId(null);
      void queryClient.invalidateQueries({ queryKey: ["purchases"] });
      void queryClient.invalidateQueries({ queryKey: ["payables"] });
    },
  });

  return (
    <DashboardLayout
      title="Compras"
      description="Mercadería recibida de tus proveedores."
      actions={
        <Button asChild>
          <Link to="/compras/nueva">
            <Plus /> Nueva compra
          </Link>
        </Button>
      }
    >
      {list.isPending && <Skeleton className="h-24 w-full" />}
      {list.isError && <ErrorState onRetry={() => void list.refetch()} />}
      {list.data?.length === 0 && (
        <EmptyState
          icon={PackagePlus}
          title="Aún no registras compras"
          description="Registra una compra para ingresar stock con su costo."
          action={
            <Button asChild>
              <Link to="/compras/nueva">Nueva compra</Link>
            </Button>
          }
        />
      )}
      {list.data && list.data.length > 0 && (
        <Card className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Compra</TableHead>
                <TableHead>Proveedor</TableHead>
                <TableHead>Fecha</TableHead>
                <TableHead>Estado</TableHead>
                <TableHead className="text-right">Total</TableHead>
                <TableHead className="text-right">Saldo</TableHead>
                <TableHead className="w-24" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {list.data.map((row) => (
                <TableRow key={row.id}>
                  <TableCell className="font-medium tabular-nums">
                    {row.supplier_document ?? `C-${String(row.number).padStart(5, "0")}`}
                  </TableCell>
                  <TableCell>{row.supplier_name}</TableCell>
                  <TableCell>
                    {new Date(`${row.issued_date}T00:00:00`).toLocaleDateString("es-PE")}
                  </TableCell>
                  <TableCell>
                    {row.status === "voided" ? (
                      <StatusChip label="Anulada" tone="danger" />
                    ) : (
                      <StatusChip
                        label={row.payment_condition === "credit" ? "A crédito" : "Contado"}
                        tone={row.payment_condition === "credit" ? "warning" : "success"}
                      />
                    )}
                  </TableCell>
                  <TableCell className="text-right tabular-nums">{formatPen(row.total)}</TableCell>
                  <TableCell className="text-right tabular-nums">
                    {formatPen(row.balance)}
                  </TableCell>
                  <TableCell className="space-x-1 text-right">
                    {row.status === "confirmed" && (
                      <Button variant="ghost" size="sm" onClick={() => setReturnId(row.id)}>
                        Devolver
                      </Button>
                    )}
                    {row.status === "confirmed" && (
                      <Button
                        variant="ghost"
                        size="sm"
                        className="text-destructive"
                        onClick={() => {
                          voidPurchase.reset();
                          setReason("");
                          setVoidId(row.id);
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

      {returnId && (
        <PurchaseReturnDialog
          purchaseId={returnId}
          onClose={() => setReturnId(null)}
          onDone={() => {
            setReturnId(null);
            void queryClient.invalidateQueries({ queryKey: ["purchases"] });
            void queryClient.invalidateQueries({ queryKey: ["payables"] });
            void queryClient.invalidateQueries({ queryKey: ["inventory"] });
          }}
        />
      )}

      <FormDialog
        open={voidId !== null}
        onOpenChange={(open) => !open && setVoidId(null)}
        title="Anular compra"
        description="Se revierte el ingreso de stock. Si tiene pagos, primero debes devolverlos."
        size="sm"
        submitLabel="Anular compra"
        processingLabel="Anulando…"
        processing={voidPurchase.isProcessing}
        slow={voidPurchase.isSlow}
        onSubmit={() => void voidPurchase.run()}
      >
        <div className="grid gap-2">
          <Label htmlFor="void-reason">Motivo</Label>
          <Input
            id="void-reason"
            value={reason}
            onChange={(event) => setReason(event.target.value)}
            aria-invalid={voidPurchase.fieldErrors["reason"] !== undefined}
          />
          {voidPurchase.fieldErrors["reason"]?.[0] && (
            <p className="text-xs text-destructive">{voidPurchase.fieldErrors["reason"][0]}</p>
          )}
        </div>
      </FormDialog>
    </DashboardLayout>
  );
}

export const Route = createFileRoute("/_app/compras/")({
  component: PurchasesPage,
});
