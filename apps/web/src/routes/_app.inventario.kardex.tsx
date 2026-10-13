import { createFileRoute } from "@tanstack/react-router";
import { useQuery } from "@tanstack/react-query";
import { useState } from "react";

import { EmptyState } from "@/components/common/empty-state";
import { ErrorState } from "@/components/common/error-state";
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
import { api } from "@/lib/api";
import { formatPen } from "@/lib/money";
import { expandVariants } from "@/lib/variants";

/** Producto devuelto por la búsqueda. */
interface Product {
  id: string;
  name: string;
  variants: { id: string; name: string | null; status: string }[];
}

/** Movimiento del libro de stock. */
interface Movement {
  id: string;
  warehouse_name: string;
  movement_type: string;
  direction: "in" | "out";
  qty: string;
  unit_cost: string | null;
  balance_after: string;
  reason: string | null;
  occurred_at: string;
}

const TYPES: Record<string, string> = {
  opening: "Saldo inicial",
  purchase: "Compra",
  sale: "Venta",
  sale_return: "Devolución de venta",
  purchase_return: "Devolución a proveedor",
  adjustment_in: "Ajuste (entrada)",
  adjustment_out: "Ajuste (salida)",
  transfer_in: "Traslado (entrada)",
  transfer_out: "Traslado (salida)",
};

/**
 * Kardex: historial de movimientos de un producto con el saldo después de
 * cada uno. El libro es de solo lectura; los saldos se pueden reconstruir.
 */
function KardexPage() {
  const [term, setTerm] = useState("");
  const [selected, setSelected] = useState<{ variantId: string; name: string } | null>(null);

  const results = useQuery({
    queryKey: ["kardex-search", term],
    enabled: term.trim().length >= 2 && selected === null,
    queryFn: async () =>
      expandVariants(
        (
          await api<{ data: Product[] }>(
            `/items?status=active&q=${encodeURIComponent(term.trim())}`,
          )
        ).data,
      ),
  });

  const movements = useQuery({
    queryKey: ["inventory", "movements", selected?.variantId],
    enabled: selected !== null,
    queryFn: async () =>
      (await api<{ data: Movement[] }>(`/inventory/movements?variant_id=${selected?.variantId}`))
        .data,
  });

  return (
    <DashboardLayout title="Kardex" description="Historial de entradas y salidas de un producto.">
      <div className="mb-4 max-w-md">
        {selected ? (
          <div className="flex items-center justify-between rounded-md border border-border px-3 py-2 text-sm">
            <strong>{selected.name}</strong>
            <Button
              variant="ghost"
              size="sm"
              onClick={() => {
                setSelected(null);
                setTerm("");
              }}
            >
              Cambiar
            </Button>
          </div>
        ) : (
          <div className="relative grid gap-2">
            <Label htmlFor="kardex-search">Producto</Label>
            <Input
              id="kardex-search"
              value={term}
              onChange={(event) => setTerm(event.target.value)}
              placeholder="Nombre, SKU o código de barras"
            />
            {results.data && results.data.length > 0 && (
              <ul className="absolute top-full z-10 mt-1 w-full rounded-md border border-border bg-popover p-1 shadow-md">
                {results.data.slice(0, 8).map((item) => (
                  <li key={item.variants[0]?.id ?? item.id}>
                    <button
                      type="button"
                      className="w-full rounded px-2 py-1.5 text-left text-sm hover:bg-accent"
                      onClick={() => {
                        const variant = item.variants[0];
                        if (variant) setSelected({ variantId: variant.id, name: item.name });
                      }}
                    >
                      {item.name}
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </div>
        )}
      </div>

      {!selected && (
        <EmptyState
          title="Elige un producto"
          description="Busca el producto para ver todos sus movimientos de stock."
        />
      )}
      {selected && movements.isPending && <Skeleton className="h-24 w-full" />}
      {selected && movements.isError && <ErrorState onRetry={() => void movements.refetch()} />}
      {movements.data?.length === 0 && (
        <EmptyState title="Sin movimientos" description="Este producto aún no tiene movimientos." />
      )}
      {movements.data && movements.data.length > 0 && (
        <Card className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Fecha</TableHead>
                <TableHead>Almacén</TableHead>
                <TableHead>Movimiento</TableHead>
                <TableHead className="text-right">Entrada</TableHead>
                <TableHead className="text-right">Salida</TableHead>
                <TableHead className="text-right">Costo unit.</TableHead>
                <TableHead className="text-right">Saldo</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {movements.data.map((movement) => (
                <TableRow key={movement.id}>
                  <TableCell className="whitespace-nowrap">
                    {new Date(movement.occurred_at).toLocaleString("es-PE")}
                  </TableCell>
                  <TableCell>{movement.warehouse_name}</TableCell>
                  <TableCell>
                    {TYPES[movement.movement_type] ?? movement.movement_type}
                    {movement.reason && (
                      <span className="block text-xs text-muted-foreground">{movement.reason}</span>
                    )}
                  </TableCell>
                  <TableCell className="text-right tabular-nums">
                    {movement.direction === "in" ? Number(movement.qty) : ""}
                  </TableCell>
                  <TableCell className="text-right tabular-nums">
                    {movement.direction === "out" ? Number(movement.qty) : ""}
                  </TableCell>
                  <TableCell className="text-right tabular-nums">
                    {movement.unit_cost ? formatPen(movement.unit_cost) : "—"}
                  </TableCell>
                  <TableCell className="text-right font-medium tabular-nums">
                    {Number(movement.balance_after)}
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

export const Route = createFileRoute("/_app/inventario/kardex")({
  component: KardexPage,
});
