import { createFileRoute, useNavigate } from "@tanstack/react-router";
import { useQuery } from "@tanstack/react-query";
import { Minus, Plus, Trash2 } from "lucide-react";
import { useEffect, useMemo, useState } from "react";

import { ActionButton } from "@/components/common/action-button";
import { DashboardLayout } from "@/components/dashboard/dashboard-layout";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { useResourceList, type Warehouse } from "@/features/organization/resources";
import { useListPricing } from "@/hooks/use-list-pricing";
import { useGuardedMutation } from "@/hooks/use-guarded-mutation";
import { api } from "@/lib/api";
import { expandVariants } from "@/lib/variants";
import { formatPen, roundMoney } from "@/lib/money";

/** Producto devuelto por la búsqueda. */
interface Product {
  id: string;
  name: string;
  variants: { id: string; name: string | null; status: string; sale_price: string | null }[];
}

/** Cliente resumido. */
interface Customer {
  id: string;
  name: string;
  doc_number: string | null;
}

/** Línea del pedido en edición. */
interface Line {
  variantId: string;
  name: string;
  price: number;
  qty: number;
  /** Precio editado a mano: no se recalcula por lista. */
  manual?: boolean;
}

/**
 * Alta de pedido: cliente, productos con cantidad, anticipo opcional y notas.
 * Al registrar, el sistema reserva el stock.
 */
function NewOrderPage() {
  const navigate = useNavigate();
  const { edit } = Route.useSearch();
  const editing = edit !== undefined;
  const warehouses = useResourceList<Warehouse>("warehouses");
  const [warehouseId, setWarehouseId] = useState("");
  useEffect(() => {
    const first = warehouses.data?.find((w) => w.status === "active");
    if (!warehouseId && first) setWarehouseId(first.id);
  }, [warehouses.data, warehouseId]);

  const [customer, setCustomer] = useState<Customer | null>(null);
  const [customerTerm, setCustomerTerm] = useState("");
  const customers = useQuery({
    queryKey: ["order-customers", customerTerm],
    enabled: customerTerm.trim().length >= 2,
    queryFn: async () =>
      (
        await api<{ data: Customer[] }>(
          `/customers?status=active&q=${encodeURIComponent(customerTerm.trim())}`,
        )
      ).data,
  });

  const [productTerm, setProductTerm] = useState("");
  const products = useQuery({
    queryKey: ["order-products", productTerm],
    enabled: productTerm.trim().length >= 2,
    queryFn: async () =>
      expandVariants(
        (
          await api<{ data: Product[] }>(
            `/items?status=active&q=${encodeURIComponent(productTerm.trim())}`,
          )
        ).data,
      ),
  });

  const [lines, setLines] = useState<Line[]>([]);
  const [prepaid, setPrepaid] = useState("");
  const [method, setMethod] = useState("cash");
  const [notes, setNotes] = useState("");

  const existing = useQuery({
    queryKey: ["orders", edit],
    enabled: editing,
    queryFn: async () =>
      (
        await api<{
          data: {
            party_id: string;
            party_name: string | null;
            warehouse_id: string;
            notes: string | null;
            lines: {
              variant_id: string;
              description: string;
              qty_ordered: string;
              unit_price: string;
            }[];
          };
        }>(`/orders/${edit}`)
      ).data,
  });
  const [loaded, setLoaded] = useState(false);
  useEffect(() => {
    const order = existing.data;
    if (!order || loaded) return;
    setLoaded(true);
    setCustomer({ id: order.party_id, name: order.party_name ?? "Cliente", doc_number: null });
    setWarehouseId(order.warehouse_id);
    setNotes(order.notes ?? "");
    setLines(
      order.lines.map((line) => ({
        variantId: line.variant_id,
        name: line.description,
        price: Number(line.unit_price),
        qty: Number(line.qty_ordered),
        manual: true,
      })),
    );
  }, [existing.data, loaded]);

  useListPricing(customer?.id ?? null, lines, setLines);

  const total = useMemo(
    () => roundMoney(lines.reduce((sum, line) => sum + line.price * line.qty, 0)),
    [lines],
  );

  const addProduct = (product: Product) => {
    const variant = product.variants[0];
    if (!variant) return;
    setLines((current) => {
      const existing = current.find((line) => line.variantId === variant.id);
      if (existing) {
        return current.map((line) =>
          line.variantId === variant.id ? { ...line, qty: line.qty + 1 } : line,
        );
      }
      return [
        ...current,
        {
          variantId: variant.id,
          name: product.name,
          price: Number(variant.sale_price ?? 0),
          qty: 1,
        },
      ];
    });
    setProductTerm("");
  };

  const update = (variantId: string, patch: Partial<Line>) =>
    setLines((current) =>
      current
        .map((line) => (line.variantId === variantId ? { ...line, ...patch } : line))
        .filter((line) => line.qty > 0),
    );

  const save = useGuardedMutation({
    mutationFn: (_input: void, { signal, idempotencyKey }) =>
      api(editing ? `/orders/${edit}` : "/orders", {
        method: editing ? "PUT" : "POST",
        signal,
        idempotencyKey,
        body: {
          ...(editing ? {} : { warehouse_id: warehouseId, party_id: customer?.id }),
          notes: notes || null,
          lines: lines.map((line) => ({
            variant_id: line.variantId,
            qty: line.qty,
            unit_price: line.price,
          })),
          ...(!editing && Number(prepaid) > 0
            ? { prepaid: { method, amount: Number(prepaid) } }
            : {}),
        },
      }),
    successMessage: editing ? "Pedido actualizado" : "Pedido registrado y stock reservado",
    onSuccess: () => void navigate({ to: "/ventas/pedidos" }),
  });

  const partyError = save.fieldErrors["party_id"]?.[0];

  return (
    <DashboardLayout
      title={editing ? "Editar pedido" : "Nuevo pedido"}
      description={
        editing
          ? "Cambia productos y cantidades; la reserva se recalcula."
          : "Reserva el stock para tu cliente."
      }
    >
      <div className="grid gap-4 lg:grid-cols-[1.3fr_1fr]">
        <div className="space-y-4">
          <Card>
            <CardContent className="space-y-3 p-4">
              <Label>Cliente</Label>
              {customer ? (
                <div className="flex items-center justify-between rounded-md border border-border px-3 py-2 text-sm">
                  <span>
                    <strong>{customer.name}</strong>
                    {customer.doc_number && (
                      <span className="ml-2 text-muted-foreground">{customer.doc_number}</span>
                    )}
                  </span>
                  {!editing && (
                    <Button variant="ghost" size="sm" onClick={() => setCustomer(null)}>
                      Cambiar
                    </Button>
                  )}
                </div>
              ) : (
                <div className="relative">
                  <Input
                    value={customerTerm}
                    onChange={(event) => setCustomerTerm(event.target.value)}
                    placeholder="Busca por nombre, documento o teléfono"
                    aria-invalid={partyError !== undefined}
                  />
                  {partyError && <p className="mt-1 text-xs text-destructive">{partyError}</p>}
                  {customers.data && customers.data.length > 0 && (
                    <ul className="absolute z-10 mt-1 w-full rounded-md border border-border bg-popover p-1 shadow-md">
                      {customers.data.slice(0, 6).map((item) => (
                        <li key={item.id}>
                          <button
                            type="button"
                            className="w-full rounded px-2 py-1.5 text-left text-sm hover:bg-accent"
                            onClick={() => {
                              setCustomer(item);
                              setCustomerTerm("");
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
            </CardContent>
          </Card>

          <Card>
            <CardContent className="space-y-3 p-4">
              <Label>Productos</Label>
              <div className="relative">
                <Input
                  value={productTerm}
                  onChange={(event) => setProductTerm(event.target.value)}
                  placeholder="Busca por nombre, SKU o código de barras"
                />
                {products.data && products.data.length > 0 && (
                  <ul className="absolute z-10 mt-1 w-full rounded-md border border-border bg-popover p-1 shadow-md">
                    {products.data.slice(0, 8).map((item) => (
                      <li key={item.id}>
                        <button
                          type="button"
                          className="flex w-full justify-between rounded px-2 py-1.5 text-left text-sm hover:bg-accent"
                          onClick={() => addProduct(item)}
                        >
                          <span>{item.name}</span>
                          <span className="text-muted-foreground">
                            {item.variants[0]?.sale_price
                              ? formatPen(item.variants[0].sale_price)
                              : "Sin precio"}
                          </span>
                        </button>
                      </li>
                    ))}
                  </ul>
                )}
              </div>

              <ul className="divide-y divide-border">
                {lines.map((line) => (
                  <li key={line.variantId} className="flex items-center gap-2 py-2">
                    <span className="min-w-0 flex-1 truncate text-sm font-medium">{line.name}</span>
                    <Input
                      aria-label={`Precio de ${line.name}`}
                      className="h-8 w-24 text-right"
                      inputMode="decimal"
                      value={line.price}
                      onChange={(event) =>
                        update(line.variantId, {
                          price: Number(event.target.value) || 0,
                          manual: true,
                        })
                      }
                    />
                    <div className="flex items-center gap-1">
                      <Button
                        variant="outline"
                        size="icon"
                        className="size-7"
                        aria-label={`Quitar uno de ${line.name}`}
                        onClick={() => update(line.variantId, { qty: line.qty - 1 })}
                      >
                        <Minus className="size-3" />
                      </Button>
                      <span className="w-8 text-center text-sm tabular-nums">{line.qty}</span>
                      <Button
                        variant="outline"
                        size="icon"
                        className="size-7"
                        aria-label={`Agregar uno de ${line.name}`}
                        onClick={() => update(line.variantId, { qty: line.qty + 1 })}
                      >
                        <Plus className="size-3" />
                      </Button>
                    </div>
                    <Button
                      variant="ghost"
                      size="icon"
                      className="size-7 text-muted-foreground"
                      aria-label={`Eliminar ${line.name}`}
                      onClick={() => update(line.variantId, { qty: 0 })}
                    >
                      <Trash2 className="size-3.5" />
                    </Button>
                  </li>
                ))}
              </ul>
              {lines.length === 0 && (
                <p className="text-sm text-muted-foreground">Agrega al menos un producto.</p>
              )}
            </CardContent>
          </Card>
        </div>

        <Card>
          <CardContent className="space-y-4 p-4">
            <div className="grid gap-2">
              <Label htmlFor="order-warehouse">Almacén</Label>
              <select
                id="order-warehouse"
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
            </div>
            <div className={editing ? "hidden" : "grid grid-cols-2 gap-3"}>
              <div className="grid gap-2">
                <Label htmlFor="order-prepaid">Anticipo (opcional)</Label>
                <Input
                  id="order-prepaid"
                  inputMode="decimal"
                  value={prepaid}
                  onChange={(event) => setPrepaid(event.target.value)}
                />
              </div>
              <div className="grid gap-2">
                <Label htmlFor="order-method">Medio</Label>
                <select
                  id="order-method"
                  value={method}
                  onChange={(event) => setMethod(event.target.value)}
                  className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                >
                  <option value="cash">Efectivo</option>
                  <option value="yape">Yape</option>
                  <option value="plin">Plin</option>
                  <option value="transfer">Transferencia</option>
                </select>
              </div>
            </div>
            <div className="grid gap-2">
              <Label htmlFor="order-notes">Notas</Label>
              <Input
                id="order-notes"
                value={notes}
                onChange={(event) => setNotes(event.target.value)}
              />
            </div>
            <div className="flex items-center justify-between border-t border-border pt-3">
              <span className="text-sm text-muted-foreground">Total</span>
              <span className="text-2xl font-bold tabular-nums">{formatPen(total)}</span>
            </div>
            <ActionButton
              className="w-full"
              size="lg"
              disabled={!customer || lines.length === 0 || !warehouseId}
              processing={save.isProcessing}
              processingLabel={editing ? "Guardando…" : "Registrando pedido…"}
              onClick={() => void save.run()}
            >
              {editing ? "Guardar cambios" : "Registrar pedido"}
            </ActionButton>
            {!customer && (
              <p className="text-xs text-muted-foreground">Elige un cliente para continuar.</p>
            )}
          </CardContent>
        </Card>
      </div>
    </DashboardLayout>
  );
}

export const Route = createFileRoute("/_app/ventas/pedidos/nuevo")({
  validateSearch: (search: Record<string, unknown>): { edit?: string | undefined } => {
    const edit = search["edit"];
    return typeof edit === "string" ? { edit } : {};
  },
  component: NewOrderPage,
});
