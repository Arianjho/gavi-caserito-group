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
import { useResourceList, type Company } from "@/features/organization/resources";
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
 * Alta de cotización: cliente, productos con precio y cantidad, vigencia y
 * condiciones. No reserva stock; se convierte en pedido desde la lista.
 */
function NewQuotationPage() {
  const navigate = useNavigate();
  const companies = useResourceList<Company>("companies");
  const [companyId, setCompanyId] = useState("");
  useEffect(() => {
    const first = companies.data?.find((c) => c.status === "active");
    if (!companyId && first) setCompanyId(first.id);
  }, [companies.data, companyId]);

  const [customer, setCustomer] = useState<Customer | null>(null);
  const [customerTerm, setCustomerTerm] = useState("");
  const customers = useQuery({
    queryKey: ["quote-customers", customerTerm],
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
    queryKey: ["quote-products", productTerm],
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
  const [validUntil, setValidUntil] = useState("");
  const [notes, setNotes] = useState("");

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
      api("/quotations", {
        method: "POST",
        signal,
        idempotencyKey,
        body: {
          company_id: companyId,
          valid_until: validUntil || null,
          party_id: customer?.id,
          notes: notes || null,
          lines: lines.map((line) => ({
            variant_id: line.variantId,
            qty: line.qty,
            unit_price: line.price,
          })),
        },
      }),
    successMessage: "Cotización registrada",
    onSuccess: () => void navigate({ to: "/ventas/cotizaciones" }),
  });

  const partyError = save.fieldErrors["party_id"]?.[0];

  return (
    <DashboardLayout title="Nueva cotización" description="Propuesta de precios; no reserva stock.">
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
                  <Button variant="ghost" size="sm" onClick={() => setCustomer(null)}>
                    Cambiar
                  </Button>
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
              <Label htmlFor="q-company">Empresa</Label>
              <select
                id="q-company"
                value={companyId}
                onChange={(event) => setCompanyId(event.target.value)}
                className="h-9 rounded-md border border-input bg-background px-3 text-sm"
              >
                {(companies.data ?? [])
                  .filter((c) => c.status === "active")
                  .map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.trade_name ?? c.legal_name}
                    </option>
                  ))}
              </select>
            </div>
            <div className="grid gap-2">
              <Label htmlFor="q-valid">Válida hasta (opcional)</Label>
              <Input
                id="q-valid"
                type="date"
                value={validUntil}
                onChange={(event) => setValidUntil(event.target.value)}
              />
            </div>
            <div className="grid gap-2">
              <Label htmlFor="q-notes">Notas</Label>
              <Input
                id="q-notes"
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
              disabled={!customer || lines.length === 0 || !companyId}
              processing={save.isProcessing}
              processingLabel="Registrando cotización…"
              onClick={() => void save.run()}
            >
              Registrar cotización
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

export const Route = createFileRoute("/_app/ventas/cotizaciones/nuevo")({
  component: NewQuotationPage,
});
