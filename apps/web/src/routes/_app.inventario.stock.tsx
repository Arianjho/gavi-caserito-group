import { createFileRoute } from "@tanstack/react-router";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Boxes, PackagePlus } from "lucide-react";
import { useState } from "react";

import { EmptyState } from "@/components/common/empty-state";
import { ErrorState } from "@/components/common/error-state";
import { FormDialog } from "@/components/common/form-dialog";
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
  TableFooter,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { useResourceList, type Warehouse } from "@/features/organization/resources";
import { useGuardedMutation } from "@/hooks/use-guarded-mutation";
import { api } from "@/lib/api";
import { expandVariants } from "@/lib/variants";
import { formatPen, roundMoney } from "@/lib/money";

/** Fila de existencias. */
interface StockRow {
  warehouse_id: string;
  warehouse_name: string;
  variant_id: string;
  item_name: string;
  sku: string | null;
  on_hand: string;
  avg_cost: string;
}

/** Producto resumido para el selector. */
interface ProductOption {
  id: string;
  name: string;
  kind: "good" | "service";
  variants: { id: string; name: string | null; status: string }[];
}

/** Tipos de movimiento manual. */
const TYPES = [
  { value: "opening", label: "Saldo inicial" },
  { value: "adjustment_in", label: "Entrada por ajuste" },
  { value: "adjustment_out", label: "Salida por ajuste" },
] as const;

/**
 * Pantalla de existencias por almacén, con registro de saldo inicial y ajustes.
 */
function StockPage() {
  const queryClient = useQueryClient();
  const warehouses = useResourceList<Warehouse>("warehouses");
  const stock = useQuery({
    queryKey: ["inventory", "stock"],
    queryFn: async () => (await api<{ data: StockRow[] }>("/inventory/stock")).data,
  });
  const products = useQuery({
    queryKey: ["resource", "items", "options"],
    queryFn: async () =>
      expandVariants(
        (await api<{ data: ProductOption[] }>("/items?status=active&per_page=200")).data,
      ),
  });

  const [open, setOpen] = useState(false);
  const [warehouseId, setWarehouseId] = useState("");
  const [variantId, setVariantId] = useState("");
  const [type, setType] = useState<(typeof TYPES)[number]["value"]>("opening");
  const [qty, setQty] = useState("");
  const [cost, setCost] = useState("");
  const [reason, setReason] = useState("");

  const save = useGuardedMutation({
    mutationFn: (_input: void, { signal, idempotencyKey }) =>
      api("/inventory/adjustments", {
        method: "POST",
        signal,
        idempotencyKey,
        body: {
          warehouse_id: warehouseId,
          type,
          reason: reason || null,
          lines: [
            {
              variant_id: variantId,
              qty: Number(qty),
              unit_cost: cost === "" ? null : Number(cost),
            },
          ],
        },
      }),
    successMessage: "Stock actualizado",
    onSuccess: () => {
      setOpen(false);
      void queryClient.invalidateQueries({ queryKey: ["inventory"] });
    },
  });

  const openDialog = () => {
    save.reset();
    const activeWarehouses = (warehouses.data ?? []).filter((w) => w.status === "active");
    setWarehouseId(activeWarehouses[0]?.id ?? "");
    setVariantId(products.data?.find((p) => p.kind === "good")?.variants[0]?.id ?? "");
    setType("opening");
    setQty("");
    setCost("");
    setReason("");
    setOpen(true);
  };

  const err = (name: string) =>
    save.fieldErrors[name]?.[0] ?? save.fieldErrors[`lines.0.${name}`]?.[0] ?? undefined;

  return (
    <DashboardLayout
      title="Stock"
      description="Existencias por almacén y costo promedio."
      actions={
        <Button onClick={openDialog}>
          <PackagePlus /> Registrar stock
        </Button>
      }
    >
      {stock.isPending && <Skeleton className="h-24 w-full" />}
      {stock.isError && <ErrorState onRetry={() => void stock.refetch()} />}
      {stock.data?.length === 0 && (
        <EmptyState
          icon={Boxes}
          title="Aún no hay existencias"
          description="Registra el saldo inicial de tus productos para poder venderlos."
          action={<Button onClick={openDialog}>Registrar saldo inicial</Button>}
        />
      )}
      {stock.data && stock.data.length > 0 && (
        <Card className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Producto</TableHead>
                <TableHead>Almacén</TableHead>
                <TableHead className="text-right">Existencias</TableHead>
                <TableHead className="text-right">Costo promedio</TableHead>
                <TableHead className="text-right">Valor</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {stock.data.map((row) => (
                <TableRow key={`${row.warehouse_id}-${row.variant_id}`}>
                  <TableCell>
                    <div className="font-medium">{row.item_name}</div>
                    {row.sku && <div className="text-xs text-muted-foreground">{row.sku}</div>}
                  </TableCell>
                  <TableCell>{row.warehouse_name}</TableCell>
                  <TableCell className="text-right tabular-nums">
                    {Number(row.on_hand).toLocaleString("es-PE")}
                  </TableCell>
                  <TableCell className="text-right tabular-nums">
                    {formatPen(row.avg_cost)}
                  </TableCell>
                  <TableCell className="text-right tabular-nums">
                    {formatPen(roundMoney(Number(row.on_hand) * Number(row.avg_cost)))}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
            <TableFooter>
              <TableRow>
                <TableCell colSpan={4} className="text-right font-medium">
                  Valor del inventario (a costo promedio)
                </TableCell>
                <TableCell className="text-right font-bold tabular-nums">
                  {formatPen(
                    roundMoney(
                      stock.data.reduce(
                        (sum, row) => sum + Number(row.on_hand) * Number(row.avg_cost),
                        0,
                      ),
                    ),
                  )}
                </TableCell>
              </TableRow>
            </TableFooter>
          </Table>
        </Card>
      )}

      <FormDialog
        open={open}
        onOpenChange={setOpen}
        title="Registrar stock"
        description="Carga un saldo inicial o corrige existencias."
        submitLabel="Registrar"
        processingLabel="Registrando…"
        processing={save.isProcessing}
        slow={save.isSlow}
        onSubmit={() => void save.run()}
      >
        <div className="grid grid-cols-2 gap-3">
          <div className="grid gap-2">
            <Label htmlFor="s-warehouse">Almacén</Label>
            <select
              id="s-warehouse"
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
            {err("warehouse_id") && (
              <p className="text-xs text-destructive">{err("warehouse_id")}</p>
            )}
          </div>
          <div className="grid gap-2">
            <Label htmlFor="s-type">Tipo</Label>
            <select
              id="s-type"
              value={type}
              onChange={(event) => setType(event.target.value as typeof type)}
              className="h-9 rounded-md border border-input bg-background px-3 text-sm"
            >
              {TYPES.map((item) => (
                <option key={item.value} value={item.value}>
                  {item.label}
                </option>
              ))}
            </select>
          </div>
        </div>
        <div className="grid gap-2">
          <Label htmlFor="s-product">Producto</Label>
          <select
            id="s-product"
            value={variantId}
            onChange={(event) => setVariantId(event.target.value)}
            className="h-9 rounded-md border border-input bg-background px-3 text-sm"
          >
            {(products.data ?? [])
              .filter((p) => p.kind === "good")
              .map((p) => (
                <option key={p.id} value={p.variants[0]?.id}>
                  {p.name}
                </option>
              ))}
          </select>
          {err("variant_id") && <p className="text-xs text-destructive">{err("variant_id")}</p>}
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div className="grid gap-2">
            <Label htmlFor="s-qty">Cantidad</Label>
            <Input
              id="s-qty"
              inputMode="decimal"
              value={qty}
              onChange={(event) => setQty(event.target.value)}
              aria-invalid={err("qty") !== undefined}
            />
            {err("qty") && <p className="text-xs text-destructive">{err("qty")}</p>}
          </div>
          {type !== "adjustment_out" && (
            <div className="grid gap-2">
              <Label htmlFor="s-cost">Costo unitario</Label>
              <Input
                id="s-cost"
                inputMode="decimal"
                value={cost}
                onChange={(event) => setCost(event.target.value)}
              />
            </div>
          )}
        </div>
        <div className="grid gap-2">
          <Label htmlFor="s-reason">Motivo (opcional)</Label>
          <Input id="s-reason" value={reason} onChange={(event) => setReason(event.target.value)} />
        </div>
      </FormDialog>
    </DashboardLayout>
  );
}

export const Route = createFileRoute("/_app/inventario/stock")({
  component: StockPage,
});
