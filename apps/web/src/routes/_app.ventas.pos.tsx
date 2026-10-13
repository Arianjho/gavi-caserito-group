import { createFileRoute } from "@tanstack/react-router";
import { useQuery } from "@tanstack/react-query";
import { Minus, Plus, ScanBarcode, ShoppingCart, Trash2 } from "lucide-react";
import { useEffect, useMemo, useRef, useState, type FormEvent } from "react";
import { toast } from "sonner";

import { ActionButton } from "@/components/common/action-button";
import { EmptyState } from "@/components/common/empty-state";
import { FormDialog } from "@/components/common/form-dialog";
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

/** Producto devuelto por la búsqueda del POS. */
interface PosProduct {
  id: string;
  name: string;
  kind: "good" | "service";
  variants: {
    id: string;
    sku: string | null;
    name: string | null;
    status: string;
    sale_price: string | null;
  }[];
}

/** Cliente resumido para el selector del POS. */
interface PosCustomer {
  id: string;
  name: string;
  doc_number: string | null;
}

/** Línea del carrito. */
interface CartLine {
  variantId: string;
  name: string;
  price: number;
  qty: number;
}

/** Venta puesta en espera (se guarda en el navegador). */
interface HeldSale {
  id: string;
  savedAt: number;
  customer: PosCustomer | null;
  cart: CartLine[];
}

const HELD_KEY = "caserito-pos-held";

/** Lee las ventas en espera; ignora datos corruptos o almacenamiento bloqueado. */
function loadHeld(): HeldSale[] {
  try {
    const raw = localStorage.getItem(HELD_KEY);
    const parsed: unknown = raw ? JSON.parse(raw) : [];
    return Array.isArray(parsed) ? (parsed as HeldSale[]) : [];
  } catch {
    return [];
  }
}

/** Guarda las ventas en espera sin fallar si no hay almacenamiento. */
function saveHeld(held: HeldSale[]): void {
  try {
    localStorage.setItem(HELD_KEY, JSON.stringify(held));
  } catch {
    // Sin almacenamiento: la espera solo dura mientras la pestaña esté abierta.
  }
}

/** Medios de pago del POS. */
const METHODS = [
  { value: "cash", label: "Efectivo" },
  { value: "yape", label: "Yape" },
  { value: "plin", label: "Plin" },
  { value: "card", label: "Tarjeta" },
  { value: "transfer", label: "Transferencia" },
] as const;

type Method = (typeof METHODS)[number]["value"];

/**
 * Punto de venta: busca o escanea productos, arma el carrito y cobra.
 *
 * El lector de código de barras escribe el código y envía Enter; si hay una
 * coincidencia exacta se agrega al carrito sin tocar el mouse.
 */
function PosPage() {
  const warehouses = useResourceList<Warehouse>("warehouses");
  const [warehouseId, setWarehouseId] = useState("");
  const [term, setTerm] = useState("");
  const [debounced, setDebounced] = useState("");
  const [cart, setCart] = useState<CartLine[]>([]);
  const [customer, setCustomer] = useState<PosCustomer | null>(null);
  const [customerTerm, setCustomerTerm] = useState("");
  const [customerDebounced, setCustomerDebounced] = useState("");
  const [payOpen, setPayOpen] = useState(false);
  const [method, setMethod] = useState<Method>("cash");
  const [tendered, setTendered] = useState("");
  const [onCredit, setOnCredit] = useState(false);
  const [dueDate, setDueDate] = useState("");
  const [initialPay, setInitialPay] = useState("");
  const searchRef = useRef<HTMLInputElement>(null);
  const [held, setHeld] = useState<HeldSale[]>(loadHeld);

  const updateHeld = (next: HeldSale[]) => {
    setHeld(next);
    saveHeld(next);
  };

  /** Guarda el carrito actual para atender a otro cliente. */
  const holdCurrent = () => {
    if (cart.length === 0) return;
    updateHeld([...held, { id: crypto.randomUUID(), savedAt: Date.now(), customer, cart }]);
    setCart([]);
    setCustomer(null);
    searchRef.current?.focus();
  };

  /** Recupera una venta en espera; si hay un carrito abierto, este pasa a espera. */
  const resume = (id: string) => {
    const target = held.find((entry) => entry.id === id);
    if (!target) return;
    const rest = held.filter((entry) => entry.id !== id);
    updateHeld(
      cart.length > 0
        ? [...rest, { id: crypto.randomUUID(), savedAt: Date.now(), customer, cart }]
        : rest,
    );
    setCart(target.cart);
    setCustomer(target.customer);
  };

  useEffect(() => {
    const first = warehouses.data?.find((w) => w.status === "active");
    if (!warehouseId && first) setWarehouseId(first.id);
  }, [warehouses.data, warehouseId]);

  useEffect(() => {
    const timer = window.setTimeout(() => setDebounced(term.trim()), 250);
    return () => window.clearTimeout(timer);
  }, [term]);

  useEffect(() => {
    const timer = window.setTimeout(() => setCustomerDebounced(customerTerm.trim()), 250);
    return () => window.clearTimeout(timer);
  }, [customerTerm]);

  const customers = useQuery({
    queryKey: ["pos-customers", customerDebounced],
    enabled: customerDebounced.length >= 2,
    queryFn: async () =>
      (
        await api<{ data: PosCustomer[] }>(
          `/customers?status=active&q=${encodeURIComponent(customerDebounced)}`,
        )
      ).data,
  });

  const results = useQuery({
    queryKey: ["pos-search", debounced],
    enabled: debounced.length >= 2,
    queryFn: async () =>
      expandVariants(
        (
          await api<{ data: PosProduct[] }>(
            `/items?status=active&q=${encodeURIComponent(debounced)}`,
          )
        ).data,
      ),
  });

  useListPricing(customer?.id ?? null, cart, setCart);

  const total = useMemo(
    () => roundMoney(cart.reduce((sum, line) => sum + line.price * line.qty, 0)),
    [cart],
  );
  const change = method === "cash" && tendered !== "" ? roundMoney(Number(tendered) - total) : 0;

  const addToCart = (product: PosProduct) => {
    const variant = product.variants[0];
    if (!variant) return;
    if (variant.sale_price === null) {
      toast.warning(`"${product.name}" no tiene precio de venta.`);
      return;
    }
    setCart((current) => {
      const existing = current.find((line) => line.variantId === variant.id);
      if (existing) {
        return current.map((line) =>
          line.variantId === variant.id ? { ...line, qty: line.qty + 1 } : line,
        );
      }
      return [
        ...current,
        { variantId: variant.id, name: product.name, price: Number(variant.sale_price), qty: 1 },
      ];
    });
    setTerm("");
    searchRef.current?.focus();
  };

  const onSearchSubmit = async (event: FormEvent) => {
    event.preventDefault();
    const code = term.trim();
    if (!code) return;
    try {
      const response = await api<{ data: PosProduct | null }>(
        `/items/lookup?code=${encodeURIComponent(code)}`,
      );
      if (response.data) addToCart(response.data);
    } catch {
      const first = results.data?.[0];
      if (first) addToCart(first);
      else toast.warning("No encontramos ese producto.");
    }
  };

  const setQty = (variantId: string, qty: number) => {
    setCart((current) =>
      qty <= 0
        ? current.filter((line) => line.variantId !== variantId)
        : current.map((line) => (line.variantId === variantId ? { ...line, qty } : line)),
    );
  };

  const sale = useGuardedMutation({
    mutationFn: (_input: void, { signal, idempotencyKey }) =>
      api<{ data: { series: string; number: number } }>("/sales", {
        method: "POST",
        signal,
        idempotencyKey,
        body: {
          warehouse_id: warehouseId,
          party_id: customer?.id ?? null,
          lines: cart.map((line) => ({
            variant_id: line.variantId,
            qty: line.qty,
            unit_price: line.price,
          })),
          on_credit: onCredit,
          due_date: onCredit ? dueDate : null,
          payments: onCredit
            ? Number(initialPay) > 0
              ? [{ method, amount: Number(initialPay) }]
              : []
            : [{ method, amount: total }],
        },
      }),
    successMessage: (result) =>
      `Venta ${result.data.series}-${String(result.data.number).padStart(6, "0")} registrada`,
    onSuccess: () => {
      setPayOpen(false);
      setCart([]);
      setCustomer(null);
      setCustomerTerm("");
      setTendered("");
      setOnCredit(false);
      setInitialPay("");
      setMethod("cash");
      searchRef.current?.focus();
    },
  });

  const openPay = () => {
    sale.reset();
    setTendered(total.toFixed(2));
    setOnCredit(false);
    setInitialPay("");
    setDueDate(new Date(Date.now() + 15 * 86_400_000).toISOString().slice(0, 10));
    setPayOpen(true);
  };

  return (
    <DashboardLayout title="Punto de venta" description="Escanea o busca y cobra.">
      <div className="grid gap-4 lg:grid-cols-[1.4fr_1fr]">
        <div className="space-y-3">
          <form onSubmit={(event) => void onSearchSubmit(event)} className="relative">
            <ScanBarcode className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              ref={searchRef}
              autoFocus
              value={term}
              onChange={(event) => setTerm(event.target.value)}
              placeholder="Escanea el código o escribe el nombre"
              className="h-11 pl-9 text-base"
              aria-label="Buscar producto"
            />
          </form>

          {results.data && results.data.length > 0 && (
            <div className="grid gap-2 sm:grid-cols-2">
              {results.data.slice(0, 12).map((product) => {
                const price = product.variants[0]?.sale_price;
                return (
                  <button
                    key={product.id}
                    type="button"
                    onClick={() => addToCart(product)}
                    className="flex items-center justify-between gap-2 rounded-lg border border-border bg-card p-3 text-left transition-colors hover:border-primary"
                  >
                    <span className="min-w-0 truncate text-sm font-medium">{product.name}</span>
                    <span className="shrink-0 text-sm tabular-nums text-muted-foreground">
                      {price ? formatPen(price) : "Sin precio"}
                    </span>
                  </button>
                );
              })}
            </div>
          )}
          {results.data?.length === 0 && debounced.length >= 2 && (
            <p className="text-sm text-muted-foreground">Sin resultados para "{debounced}".</p>
          )}
        </div>

        <Card>
          <CardContent className="flex flex-col gap-3 p-4">
            <div className="flex items-center justify-between gap-2">
              <h2 className="font-semibold">Carrito</h2>
              <select
                aria-label="Almacén"
                value={warehouseId}
                onChange={(event) => setWarehouseId(event.target.value)}
                className="h-8 max-w-[50%] rounded-md border border-input bg-background px-2 text-xs"
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

            {(held.length > 0 || cart.length > 0) && (
              <div className="flex flex-wrap items-center gap-2">
                {cart.length > 0 && (
                  <Button variant="outline" size="sm" onClick={holdCurrent}>
                    Poner en espera
                  </Button>
                )}
                {held.map((entry) => (
                  <span key={entry.id} className="flex items-center gap-1">
                    <Button variant="secondary" size="sm" onClick={() => resume(entry.id)}>
                      {entry.customer?.name ?? "Sin cliente"} · {entry.cart.length} ítems
                    </Button>
                    <Button
                      variant="ghost"
                      size="icon"
                      className="size-7"
                      aria-label="Descartar venta en espera"
                      onClick={() => updateHeld(held.filter((h) => h.id !== entry.id))}
                    >
                      ×
                    </Button>
                  </span>
                ))}
              </div>
            )}

            <div className="relative">
              {customer ? (
                <div className="flex items-center justify-between rounded-md border border-border px-3 py-2 text-sm">
                  <span className="truncate">
                    Cliente: <strong>{customer.name}</strong>
                  </span>
                  <Button variant="ghost" size="sm" onClick={() => setCustomer(null)}>
                    Quitar
                  </Button>
                </div>
              ) : (
                <>
                  <Input
                    value={customerTerm}
                    onChange={(event) => setCustomerTerm(event.target.value)}
                    placeholder="Cliente (opcional): nombre o documento"
                    aria-label="Buscar cliente"
                    className="h-9 text-sm"
                  />
                  {customers.data && customers.data.length > 0 && customerDebounced.length >= 2 && (
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
                            {item.doc_number && (
                              <span className="ml-2 text-xs text-muted-foreground">
                                {item.doc_number}
                              </span>
                            )}
                          </button>
                        </li>
                      ))}
                    </ul>
                  )}
                </>
              )}
            </div>

            {cart.length === 0 ? (
              <EmptyState
                icon={ShoppingCart}
                title="Carrito vacío"
                description="Escanea un producto para empezar."
              />
            ) : (
              <ul className="divide-y divide-border">
                {cart.map((line) => (
                  <li key={line.variantId} className="flex items-center gap-2 py-2">
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-sm font-medium">{line.name}</p>
                      <p className="text-xs tabular-nums text-muted-foreground">
                        {formatPen(line.price)}
                      </p>
                    </div>
                    <div className="flex items-center gap-1">
                      <Button
                        variant="outline"
                        size="icon"
                        className="size-7"
                        aria-label={`Quitar uno de ${line.name}`}
                        onClick={() => setQty(line.variantId, line.qty - 1)}
                      >
                        <Minus className="size-3" />
                      </Button>
                      <span className="w-8 text-center text-sm tabular-nums">{line.qty}</span>
                      <Button
                        variant="outline"
                        size="icon"
                        className="size-7"
                        aria-label={`Agregar uno de ${line.name}`}
                        onClick={() => setQty(line.variantId, line.qty + 1)}
                      >
                        <Plus className="size-3" />
                      </Button>
                    </div>
                    <span className="w-20 text-right text-sm font-medium tabular-nums">
                      {formatPen(roundMoney(line.price * line.qty))}
                    </span>
                    <Button
                      variant="ghost"
                      size="icon"
                      className="size-7 text-muted-foreground"
                      aria-label={`Eliminar ${line.name}`}
                      onClick={() => setQty(line.variantId, 0)}
                    >
                      <Trash2 className="size-3.5" />
                    </Button>
                  </li>
                ))}
              </ul>
            )}

            <div className="flex items-center justify-between border-t border-border pt-3">
              <span className="text-sm text-muted-foreground">Total</span>
              <span className="text-2xl font-bold tabular-nums">{formatPen(total)}</span>
            </div>
            <ActionButton
              size="lg"
              disabled={cart.length === 0 || !warehouseId}
              processing={false}
              onClick={openPay}
            >
              Cobrar {cart.length > 0 && formatPen(total)}
            </ActionButton>
          </CardContent>
        </Card>
      </div>

      <FormDialog
        open={payOpen}
        onOpenChange={setPayOpen}
        title="Cobrar venta"
        description={`Total a cobrar: ${formatPen(total)}`}
        size="sm"
        submitLabel={
          onCredit ? "Registrar venta a crédito" : `Registrar cobro de ${formatPen(total)}`
        }
        processingLabel="Registrando venta…"
        processing={sale.isProcessing}
        slow={sale.isSlow}
        onSubmit={() => void sale.run()}
      >
        <div className="grid gap-2">
          <Label>Medio de pago</Label>
          <div className="flex flex-wrap gap-2">
            {METHODS.map((item) => (
              <Button
                key={item.value}
                type="button"
                size="sm"
                variant={method === item.value ? "default" : "outline"}
                onClick={() => setMethod(item.value)}
              >
                {item.label}
              </Button>
            ))}
          </div>
        </div>
        <label className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            checked={onCredit}
            disabled={!customer}
            onChange={(event) => setOnCredit(event.target.checked)}
          />
          Venta a crédito {!customer && "(elige un cliente primero)"}
        </label>
        {onCredit && (
          <div className="grid grid-cols-2 gap-3">
            <div className="grid gap-2">
              <Label htmlFor="due">Vence</Label>
              <Input
                id="due"
                type="date"
                value={dueDate}
                onChange={(event) => setDueDate(event.target.value)}
              />
            </div>
            <div className="grid gap-2">
              <Label htmlFor="initial">Adelanto (opcional)</Label>
              <Input
                id="initial"
                inputMode="decimal"
                value={initialPay}
                onChange={(event) => setInitialPay(event.target.value)}
              />
            </div>
          </div>
        )}
        {method === "cash" && !onCredit && (
          <div className="grid gap-2">
            <Label htmlFor="tendered">Recibido</Label>
            <Input
              id="tendered"
              inputMode="decimal"
              value={tendered}
              onChange={(event) => setTendered(event.target.value)}
            />
            {change > 0 && (
              <p className="text-sm font-medium text-success">Vuelto: {formatPen(change)}</p>
            )}
            {tendered !== "" && change < 0 && (
              <p className="text-sm text-destructive">
                Falta {formatPen(Math.abs(change))} para completar el cobro.
              </p>
            )}
          </div>
        )}
      </FormDialog>
    </DashboardLayout>
  );
}

export const Route = createFileRoute("/_app/ventas/pos")({
  component: PosPage,
});
