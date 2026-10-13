import { createFileRoute, Link } from "@tanstack/react-router";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { FileText, Plus } from "lucide-react";
import { useEffect, useState } from "react";

import { EmptyState } from "@/components/common/empty-state";
import { ErrorState } from "@/components/common/error-state";
import { FormDialog } from "@/components/common/form-dialog";
import { StatusChip } from "@/components/common/status-chip";
import { DashboardLayout } from "@/components/dashboard/dashboard-layout";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
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
import { useResourceList, type Warehouse } from "@/features/organization/resources";
import { useGuardedMutation } from "@/hooks/use-guarded-mutation";
import { api } from "@/lib/api";
import { formatPen } from "@/lib/money";

type QuotationStatus = "open" | "converted" | "rejected" | "expired";

/** Fila del listado de cotizaciones. */
interface QuotationRow {
  id: string;
  number: number;
  status: QuotationStatus;
  total: string;
  valid_until: string | null;
  party_name: string;
  created_at: string;
}

const STATUS: Record<
  QuotationStatus,
  { label: string; tone: "info" | "success" | "neutral" | "danger" }
> = {
  open: { label: "Abierta", tone: "info" },
  converted: { label: "Convertida", tone: "success" },
  rejected: { label: "Rechazada", tone: "danger" },
  expired: { label: "Vencida", tone: "neutral" },
};

/**
 * Cotizaciones: lista con conversión a pedido (reserva el stock) y rechazo.
 */
function QuotationsPage() {
  const queryClient = useQueryClient();
  const list = useQuery({
    queryKey: ["quotations"],
    queryFn: async () => (await api<{ data: QuotationRow[] }>("/quotations")).data,
  });
  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ["quotations"] });
    void queryClient.invalidateQueries({ queryKey: ["orders"] });
  };

  const warehouses = useResourceList<Warehouse>("warehouses");
  const [convertId, setConvertId] = useState<string | null>(null);
  const [warehouseId, setWarehouseId] = useState("");
  useEffect(() => {
    const first = warehouses.data?.find((w) => w.status === "active");
    if (!warehouseId && first) setWarehouseId(first.id);
  }, [warehouses.data, warehouseId]);

  const reject = useGuardedMutation({
    mutationFn: (id: string, { signal }) =>
      api(`/quotations/${id}/reject`, { method: "POST", signal }),
    successMessage: "Cotización rechazada",
    onSuccess: refresh,
  });

  const convert = useGuardedMutation({
    mutationFn: (_input: void, { signal, idempotencyKey }) =>
      api(`/quotations/${convertId}/convert`, {
        method: "POST",
        signal,
        idempotencyKey,
        body: { warehouse_id: warehouseId },
      }),
    successMessage: "Pedido creado y stock reservado",
    onSuccess: () => {
      setConvertId(null);
      refresh();
    },
  });

  return (
    <DashboardLayout
      title="Cotizaciones"
      description="Propuestas de precio a tus clientes."
      actions={
        <Button asChild>
          <Link to="/ventas/cotizaciones/nuevo">
            <Plus /> Nueva cotización
          </Link>
        </Button>
      }
    >
      {list.isPending && <Skeleton className="h-24 w-full" />}
      {list.isError && <ErrorState onRetry={() => void list.refetch()} />}
      {list.data?.length === 0 && (
        <EmptyState
          icon={FileText}
          title="Sin cotizaciones"
          description="Crea una cotización y conviértela en pedido cuando el cliente acepte."
        />
      )}
      {list.data && list.data.length > 0 && (
        <Card className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>N.º</TableHead>
                <TableHead>Cliente</TableHead>
                <TableHead>Vigencia</TableHead>
                <TableHead className="text-right">Total</TableHead>
                <TableHead>Estado</TableHead>
                <TableHead className="w-48" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {list.data.map((row) => (
                <TableRow key={row.id}>
                  <TableCell className="tabular-nums">{row.number}</TableCell>
                  <TableCell className="font-medium">{row.party_name}</TableCell>
                  <TableCell>{row.valid_until ?? "—"}</TableCell>
                  <TableCell className="text-right tabular-nums">{formatPen(row.total)}</TableCell>
                  <TableCell>
                    <StatusChip label={STATUS[row.status].label} tone={STATUS[row.status].tone} />
                  </TableCell>
                  <TableCell className="space-x-1 text-right">
                    {row.status === "open" && (
                      <>
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => {
                            convert.reset();
                            setConvertId(row.id);
                          }}
                        >
                          Convertir en pedido
                        </Button>
                        <Button
                          variant="ghost"
                          size="sm"
                          disabled={reject.isProcessing}
                          onClick={() => void reject.run(row.id)}
                        >
                          Rechazar
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

      <FormDialog
        open={convertId !== null}
        onOpenChange={(open) => !open && setConvertId(null)}
        title="Convertir en pedido"
        submitLabel="Crear pedido"
        processingLabel="Creando pedido…"
        processing={convert.isProcessing}
        slow={convert.isSlow}
        onSubmit={() => void convert.run()}
      >
        <div className="grid gap-2">
          <Label htmlFor="q-warehouse">Almacén que reserva el stock</Label>
          <select
            id="q-warehouse"
            value={warehouseId}
            onChange={(event) => setWarehouseId(event.target.value)}
            className="h-9 rounded-md border border-input bg-background px-3 text-sm"
          >
            {(warehouses.data ?? [])
              .filter((w) => w.status === "active")
              .map((w) => (
                <option key={w.id} value={w.id}>
                  {w.name}
                </option>
              ))}
          </select>
          {convert.fieldErrors["warehouse_id"]?.[0] && (
            <p className="text-xs text-destructive">{convert.fieldErrors["warehouse_id"][0]}</p>
          )}
        </div>
      </FormDialog>
    </DashboardLayout>
  );
}

export const Route = createFileRoute("/_app/ventas/cotizaciones/")({
  component: QuotationsPage,
});
