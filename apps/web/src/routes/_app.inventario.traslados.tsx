import { createFileRoute } from "@tanstack/react-router";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowRightLeft, Plus, Trash2 } from "lucide-react";
import { useEffect, useState } from "react";

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
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { useResourceList, type Warehouse } from "@/features/organization/resources";
import { useGuardedMutation } from "@/hooks/use-guarded-mutation";
import { api } from "@/lib/api";
import { expandVariants } from "@/lib/variants";

/** Traslado registrado (una fila por producto movido). */
interface TransferRow {
  document_id: string;
  from_warehouse: string;
  name: string;
  variant_name: string | null;
  qty: string;
  reason: string | null;
  occurred_at: string;
}

/** Producto devuelto por la búsqueda. */
interface Product {
  id: string;
  name: string;
  kind: "good" | "service";
  variants: { id: string; name: string | null; status: string }[];
}

/** Línea del traslado en edición. */
interface Line {
  variantId: string;
  name: string;
  qty: string;
}

/**
 * Traslados entre almacenes: el stock sale del origen al costo promedio y
 * entra al destino con ese costo.
 */
function TransfersPage() {
  const queryClient = useQueryClient();
  const list = useQuery({
    queryKey: ["inventory", "transfers"],
    queryFn: async () => (await api<{ data: TransferRow[] }>("/inventory/transfers")).data,
  });
  const warehouses = useResourceList<Warehouse>("warehouses");
  const active = (warehouses.data ?? []).filter((w) => w.status === "active");

  const [open, setOpen] = useState(false);
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [lines, setLines] = useState<Line[]>([]);
  const [term, setTerm] = useState("");
  const [reason, setReason] = useState("");

  useEffect(() => {
    if (!from && active[0]) setFrom(active[0].id);
    if (!to && active[1]) setTo(active[1].id);
  }, [active, from, to]);

  const results = useQuery({
    queryKey: ["transfer-search", term],
    enabled: term.trim().length >= 2,
    queryFn: async () =>
      expandVariants(
        (
          await api<{ data: Product[] }>(
            `/items?status=active&q=${encodeURIComponent(term.trim())}`,
          )
        ).data,
      ).filter((product) => product.kind === "good"),
  });

  const save = useGuardedMutation({
    mutationFn: (_input: void, { signal, idempotencyKey }) =>
      api("/inventory/transfers", {
        method: "POST",
        signal,
        idempotencyKey,
        body: {
          from_warehouse_id: from,
          to_warehouse_id: to,
          reason: reason || null,
          lines: lines.map((line) => ({ variant_id: line.variantId, qty: Number(line.qty) })),
        },
      }),
    successMessage: "Traslado registrado",
    onSuccess: () => {
      setOpen(false);
      setLines([]);
      setReason("");
      void queryClient.invalidateQueries({ queryKey: ["inventory"] });
    },
  });

  const select = (value: string, set: (id: string) => void, id: string, label: string) => (
    <div className="grid gap-2">
      <Label htmlFor={id}>{label}</Label>
      <select
        id={id}
        value={value}
        onChange={(event) => set(event.target.value)}
        className="h-9 rounded-md border border-input bg-background px-3 text-sm"
      >
        {active.map((w) => (
          <option key={w.id} value={w.id}>
            {w.name}
          </option>
        ))}
      </select>
    </div>
  );

  return (
    <DashboardLayout
      title="Traslados"
      description="Mueve mercadería de un almacén a otro."
      actions={
        <Button
          disabled={active.length < 2}
          onClick={() => {
            save.reset();
            setOpen(true);
          }}
        >
          <Plus /> Nuevo traslado
        </Button>
      }
    >
      {active.length < 2 && (
        <p className="mb-4 text-sm text-muted-foreground">
          Necesitas al menos dos almacenes activos para trasladar stock.
        </p>
      )}
      {list.isPending && <Skeleton className="h-24 w-full" />}
      {list.isError && <ErrorState onRetry={() => void list.refetch()} />}
      {list.data?.length === 0 && (
        <EmptyState
          icon={ArrowRightLeft}
          title="Sin traslados"
          description="Los traslados entre almacenes aparecerán aquí."
        />
      )}
      {list.data && list.data.length > 0 && (
        <Card className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Fecha</TableHead>
                <TableHead>Producto</TableHead>
                <TableHead>Desde</TableHead>
                <TableHead className="text-right">Cantidad</TableHead>
                <TableHead>Motivo</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {list.data.map((row, index) => (
                <TableRow key={`${row.document_id}-${index}`}>
                  <TableCell className="whitespace-nowrap">
                    {new Date(row.occurred_at).toLocaleString("es-PE")}
                  </TableCell>
                  <TableCell className="font-medium">
                    {row.variant_name ? `${row.name} ${row.variant_name}` : row.name}
                  </TableCell>
                  <TableCell>{row.from_warehouse}</TableCell>
                  <TableCell className="text-right tabular-nums">{Number(row.qty)}</TableCell>
                  <TableCell className="text-muted-foreground">{row.reason ?? "—"}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Card>
      )}

      <FormDialog
        open={open}
        onOpenChange={setOpen}
        title="Nuevo traslado"
        size="lg"
        submitLabel="Registrar traslado"
        processingLabel="Trasladando…"
        processing={save.isProcessing}
        slow={save.isSlow}
        onSubmit={() => (lines.length > 0 && from !== to ? void save.run() : undefined)}
      >
        <div className="grid grid-cols-2 gap-3">
          {select(from, setFrom, "t-from", "Desde")}
          {select(to, setTo, "t-to", "Hacia")}
        </div>
        {from === to && <p className="text-xs text-destructive">Elige almacenes distintos.</p>}
        <div className="relative grid gap-2">
          <Label htmlFor="t-search">Agregar producto</Label>
          <Input
            id="t-search"
            value={term}
            onChange={(event) => setTerm(event.target.value)}
            placeholder="Nombre, SKU o código"
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
                      if (variant && !lines.some((l) => l.variantId === variant.id)) {
                        setLines([...lines, { variantId: variant.id, name: item.name, qty: "1" }]);
                      }
                      setTerm("");
                    }}
                  >
                    {item.name}
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
        <div className="space-y-2">
          {lines.map((line, index) => (
            <div key={line.variantId} className="flex items-center gap-2 text-sm">
              <span className="min-w-0 flex-1 truncate">{line.name}</span>
              <Input
                aria-label={`Cantidad de ${line.name}`}
                className="h-8 w-24 text-right"
                inputMode="decimal"
                value={line.qty}
                onChange={(event) =>
                  setLines(
                    lines.map((l, i) => (i === index ? { ...l, qty: event.target.value } : l)),
                  )
                }
              />
              <Button
                type="button"
                variant="ghost"
                size="icon"
                className="size-7"
                aria-label={`Quitar ${line.name}`}
                onClick={() => setLines(lines.filter((_, i) => i !== index))}
              >
                <Trash2 className="size-3.5" />
              </Button>
            </div>
          ))}
        </div>
        {save.fieldErrors["lines"]?.[0] && (
          <p className="text-xs text-destructive">{save.fieldErrors["lines"][0]}</p>
        )}
        <div className="grid gap-2">
          <Label htmlFor="t-reason">Motivo (opcional)</Label>
          <Input id="t-reason" value={reason} onChange={(event) => setReason(event.target.value)} />
        </div>
      </FormDialog>
    </DashboardLayout>
  );
}

export const Route = createFileRoute("/_app/inventario/traslados")({
  component: TransfersPage,
});
