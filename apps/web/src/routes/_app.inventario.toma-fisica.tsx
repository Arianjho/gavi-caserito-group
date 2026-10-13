import { createFileRoute } from "@tanstack/react-router";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { useEffect, useState } from "react";

import { ActionButton } from "@/components/common/action-button";
import { EmptyState } from "@/components/common/empty-state";
import { DashboardLayout } from "@/components/dashboard/dashboard-layout";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
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

/** Saldo de un producto en un almacén. */
interface StockRow {
  warehouse_id: string;
  variant_id: string;
  item_name: string;
  sku: string | null;
  on_hand: string;
}

/** Resultado de aplicar la toma. */
interface CountResult {
  checked: number;
  adjusted: { variant_id: string; difference: string }[];
}

/**
 * Toma física: se cuenta lo que hay en el almacén y el sistema ajusta solo
 * las diferencias. Lo que no se llena se considera no contado y no se toca.
 */
function CountPage() {
  const queryClient = useQueryClient();
  const warehouses = useResourceList<Warehouse>("warehouses");
  const active = (warehouses.data ?? []).filter((w) => w.status === "active");
  const [warehouseId, setWarehouseId] = useState("");
  useEffect(() => {
    if (!warehouseId && active[0]) setWarehouseId(active[0].id);
  }, [active, warehouseId]);

  const stock = useQuery({
    queryKey: ["inventory", "stock", "count"],
    queryFn: async () => (await api<{ data: StockRow[] }>("/inventory/stock")).data,
  });
  const rows = (stock.data ?? []).filter((row) => row.warehouse_id === warehouseId);

  const [counted, setCounted] = useState<Record<string, string>>({});
  const [result, setResult] = useState<CountResult | null>(null);

  const entries = rows
    .filter((row) => (counted[row.variant_id] ?? "") !== "")
    .map((row) => ({ variant_id: row.variant_id, counted: Number(counted[row.variant_id]) }));

  const save = useGuardedMutation({
    mutationFn: (_input: void, { signal, idempotencyKey }) =>
      api<{ data: CountResult }>("/inventory/counts", {
        method: "POST",
        signal,
        idempotencyKey,
        body: { warehouse_id: warehouseId, lines: entries },
      }),
    successMessage: "Toma física aplicada",
    onSuccess: (response) => {
      setResult(response.data);
      setCounted({});
      void queryClient.invalidateQueries({ queryKey: ["inventory"] });
    },
  });

  return (
    <DashboardLayout
      title="Toma física"
      description="Cuenta tu mercadería y ajusta las diferencias contra el sistema."
    >
      <div className="mb-4 flex flex-wrap items-end gap-3">
        <div className="grid gap-1">
          <Label htmlFor="count-warehouse">Almacén</Label>
          <select
            id="count-warehouse"
            value={warehouseId}
            onChange={(event) => {
              setWarehouseId(event.target.value);
              setCounted({});
              setResult(null);
            }}
            className="h-9 rounded-md border border-input bg-background px-3 text-sm"
          >
            {active.map((w) => (
              <option key={w.id} value={w.id}>
                {w.name}
              </option>
            ))}
          </select>
        </div>
        <ActionButton
          disabled={entries.length === 0}
          processing={save.isProcessing}
          processingLabel="Aplicando…"
          onClick={() => void save.run()}
        >
          Aplicar conteo ({entries.length})
        </ActionButton>
      </div>

      {result && (
        <p className="mb-4 rounded-md bg-muted px-3 py-2 text-sm">
          Se revisaron {result.checked} productos y se ajustaron {result.adjusted.length}.
        </p>
      )}

      {rows.length === 0 ? (
        <EmptyState
          title="Sin productos con stock"
          description="Registra un saldo inicial o una compra para poder contar este almacén."
        />
      ) : (
        <Card className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Producto</TableHead>
                <TableHead>SKU</TableHead>
                <TableHead className="text-right">En sistema</TableHead>
                <TableHead className="w-32 text-right">Contado</TableHead>
                <TableHead className="text-right">Diferencia</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {rows.map((row) => {
                const value = counted[row.variant_id] ?? "";
                const diff = value === "" ? null : Number(value) - Number(row.on_hand);
                return (
                  <TableRow key={row.variant_id}>
                    <TableCell className="font-medium">{row.item_name}</TableCell>
                    <TableCell className="tabular-nums">{row.sku ?? "—"}</TableCell>
                    <TableCell className="text-right tabular-nums">{Number(row.on_hand)}</TableCell>
                    <TableCell className="text-right">
                      <Input
                        aria-label={`Contado de ${row.item_name}`}
                        className="ml-auto h-8 w-24 text-right"
                        inputMode="decimal"
                        value={value}
                        onChange={(event) =>
                          setCounted({ ...counted, [row.variant_id]: event.target.value })
                        }
                      />
                    </TableCell>
                    <TableCell
                      className={`text-right tabular-nums ${
                        diff === null || diff === 0
                          ? "text-muted-foreground"
                          : diff < 0
                            ? "text-destructive"
                            : "text-success"
                      }`}
                    >
                      {diff === null ? "—" : diff > 0 ? `+${diff}` : diff}
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
        </Card>
      )}
    </DashboardLayout>
  );
}

export const Route = createFileRoute("/_app/inventario/toma-fisica")({
  component: CountPage,
});
