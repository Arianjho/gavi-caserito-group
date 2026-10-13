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
interface Supplier {
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
}

/**
 * Alta de compra: proveedor, productos con cantidad y costo, y condición de pago.
 * Al registrar, el stock ingresa con su costo.
 */
function NewPurchasePage() {
  const navigate = useNavigate();
  const warehouses = useResourceList<Warehouse>("warehouses");
  const [warehouseId, setWarehouseId] = useState("");
  useEffect(() => {
    const first = warehouses.data?.find((w) => w.status === "active");
    if (!warehouseId && first) setWarehouseId(first.id);
  }, [warehouses.data, warehouseId]);

  const [supplier, setSupplier] = useState<Supplier | null>(null);
  const [supplierTerm, setSupplierTerm] = useState("");
  const suppliers = useQuery({
    queryKey: ["purchase-suppliers", supplierTerm],
    enabled: supplierTerm.trim().length >= 2,
    queryFn: async () =>
      (
        await api<{ data: Supplier[] }>(
          `/suppliers?status=active&q=${encodeURIComponent(supplierTerm.trim())}`,
        )
      ).data,
  });

  const [productTerm, setProductTerm] = useState("");
  const products = useQuery({
    queryKey: ["purchase-products", productTerm],
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
  const [document, setDocument] = useState("");
  const [onCredit, setOnCredit] = useState(false);
  const [dueDate, setDueDate] = useState(
    new Date(Date.now() + 30 * 86_400_000).toISOString().slice(0, 10),
  );
  const [method, setMethod] = useState("cash");
  const [notes, setNotes] = useState("");

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
          price: 0,
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
      api("/purchases", {
        method: "POST",
        signal,
        idempotencyKey,
        body: {
          warehouse_id: warehouseId,
          notes: notes || null,
          supplier_id: supplier?.id,
          issued_date: new Date().toISOString().slice(0, 10),
          supplier_document: document || null,
          on_credit: onCredit,
          due_date: onCredit ? dueDate : null,
          method: onCredit ? null : method,
          lines: lines.map((line) => ({
            variant_id: line.variantId,
            qty: line.qty,
            unit_cost: line.price,
          })),
        },
      }),
    successMessage: "Compra registrada y stock ingresado",
    onSuccess: () => void navigate({ to: "/compras" }),
  });

  const partyError = save.fieldErrors["supplier_id"]?.[0];

  return (
    <DashboardLayout title="Nueva compra" description="Ingresa mercadería con su costo.">
      <div className="grid gap-4 lg:grid-cols-[1.3fr_1fr]">
        <div className="space-y-4">
          <Card>
            <CardContent className="space-y-3 p-4">
              <Label>Proveedor</Label>
              {supplier ? (
                <div className="flex items-center justify-between rounded-md border border-border px-3 py-2 text-sm">
                  <span>
                    <strong>{supplier.name}</strong>
                    {supplier.doc_number && (
                      <span className="ml-2 text-muted-foreground">{supplier.doc_number}</span>
                    )}
                  </span>
                  <Button variant="ghost" size="sm" onClick={() => setSupplier(null)}>
                    Cambiar
                  </Button>
                </div>
              ) : (
                <div className="relative">
                  <Input
                    value={supplierTerm}
                    onChange={(event) => setSupplierTerm(event.target.value)}
                    placeholder="Busca proveedor por nombre o RUC"
                    aria-invalid={partyError !== undefined}
                  />
                  {partyError && <p className="mt-1 text-xs text-destructive">{partyError}</p>}
                  {suppliers.data && suppliers.data.length > 0 && (
                    <ul className="absolute z-10 mt-1 w-full rounded-md border border-border bg-popover p-1 shadow-md">
                      {suppliers.data.slice(0, 6).map((item) => (
                        <li key={item.id}>
                          <button
                            type="button"
                            className="w-full rounded px-2 py-1.5 text-left text-sm hover:bg-accent"
                            onClick={() => {
                              setSupplier(item);
                              setSupplierTerm("");
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
                          <span className="text-muted-foreground"></span>
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
                      aria-label={`Costo de ${line.name}`}
                      className="h-8 w-24 text-right"
                      inputMode="decimal"
                      value={line.price}
                      onChange={(event) =>
                        update(line.variantId, { price: Number(event.target.value) || 0 })
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
              <Label htmlFor="purchase-warehouse">Almacén</Label>
              <select
                id="purchase-warehouse"
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
            <div className="grid gap-2">
              <Label htmlFor="purchase-document">Comprobante del proveedor</Label>
              <Input
                id="purchase-document"
                placeholder="F001-000123"
                value={document}
                onChange={(event) => setDocument(event.target.value)}
              />
            </div>
            <label className="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                checked={onCredit}
                onChange={(event) => setOnCredit(event.target.checked)}
              />
              Compra a crédito
            </label>
            {onCredit ? (
              <div className="grid gap-2">
                <Label htmlFor="purchase-due">Vence</Label>
                <Input
                  id="purchase-due"
                  type="date"
                  value={dueDate}
                  onChange={(event) => setDueDate(event.target.value)}
                />
              </div>
            ) : (
              <div className="grid gap-2">
                <Label htmlFor="purchase-method">Medio de pago</Label>
                <select
                  id="purchase-method"
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
            )}
            <div className="grid gap-2">
              <Label htmlFor="purchase-notes">Notas</Label>
              <Input
                id="purchase-notes"
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
              disabled={!supplier || lines.length === 0 || !warehouseId}
              processing={save.isProcessing}
              processingLabel="Registrando compra…"
              onClick={() => void save.run()}
            >
              Registrar compra
            </ActionButton>
            {!supplier && (
              <p className="text-xs text-muted-foreground">Elige un proveedor para continuar.</p>
            )}
          </CardContent>
        </Card>
      </div>
    </DashboardLayout>
  );
}

export const Route = createFileRoute("/_app/compras/nueva")({
  component: NewPurchasePage,
});
