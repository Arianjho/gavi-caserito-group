import { useQuery } from "@tanstack/react-query";
import { Trash2 } from "lucide-react";
import { useEffect, useState } from "react";

import { FormDialog } from "@/components/common/form-dialog";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { useGuardedMutation } from "@/hooks/use-guarded-mutation";
import { api } from "@/lib/api";
import { expandVariants } from "@/lib/variants";

/** Precio de una variante en la lista (texto para poder editarlo). */
interface PriceRow {
  variantId: string;
  name: string;
  minQty: string;
  price: string;
}

/** Fila devuelta por la API de la lista. */
interface ApiRow {
  variant_id: string;
  name: string;
  variant_name: string | null;
  min_qty: string;
  price: string;
}

/** Producto devuelto por la búsqueda. */
interface Product {
  id: string;
  name: string;
  variants: { id: string; name: string | null; status: string }[];
}

/** Propiedades de {@link PriceListItemsDialog}. */
export interface PriceListItemsDialogProps {
  listId: string;
  listName: string;
  onClose: () => void;
}

/**
 * Edita los precios de una lista: por producto y cantidad mínima. Una misma
 * variante puede tener varias escalas (1 unidad, 10 unidades...).
 */
export function PriceListItemsDialog({ listId, listName, onClose }: PriceListItemsDialogProps) {
  const current = useQuery({
    queryKey: ["price-list", listId],
    queryFn: async () => (await api<{ data: ApiRow[] }>(`/price-lists/${listId}/items`)).data,
  });
  const [rows, setRows] = useState<PriceRow[]>([]);
  const [term, setTerm] = useState("");

  useEffect(() => {
    if (current.data) {
      setRows(
        current.data.map((row) => ({
          variantId: row.variant_id,
          name: row.variant_name ? `${row.name} ${row.variant_name}` : row.name,
          minQty: String(Number(row.min_qty)),
          price: String(Number(row.price)),
        })),
      );
    }
  }, [current.data]);

  const results = useQuery({
    queryKey: ["price-list-search", term],
    enabled: term.trim().length >= 2,
    queryFn: async () =>
      expandVariants(
        (
          await api<{ data: Product[] }>(
            `/items?status=active&q=${encodeURIComponent(term.trim())}`,
          )
        ).data,
      ),
  });

  const save = useGuardedMutation({
    mutationFn: (_input: void, { signal }) =>
      api(`/price-lists/${listId}/items`, {
        method: "PUT",
        signal,
        body: {
          items: rows.map((row) => ({
            variant_id: row.variantId,
            min_qty: Number(row.minQty),
            price: Number(row.price),
          })),
        },
      }),
    successMessage: "Precios guardados",
    onSuccess: onClose,
  });

  const update = (index: number, patch: Partial<PriceRow>) =>
    setRows((list) => list.map((row, i) => (i === index ? { ...row, ...patch } : row)));

  return (
    <FormDialog
      open
      onOpenChange={(open) => !open && onClose()}
      title={`Precios de ${listName}`}
      description="Si un producto no figura aquí, se usa su precio base."
      size="lg"
      submitLabel="Guardar precios"
      processingLabel="Guardando…"
      processing={save.isProcessing}
      slow={save.isSlow}
      onSubmit={() => void save.run()}
    >
      <div className="relative grid gap-2">
        <Label htmlFor="pl-search">Agregar producto</Label>
        <Input
          id="pl-search"
          value={term}
          onChange={(event) => setTerm(event.target.value)}
          placeholder="Busca por nombre, SKU o código"
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
                    if (variant) {
                      setRows((list) => [
                        ...list,
                        { variantId: variant.id, name: item.name, minQty: "1", price: "" },
                      ]);
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

      <div className="max-h-72 space-y-2 overflow-y-auto">
        {rows.map((row, index) => (
          <div key={`${row.variantId}-${index}`} className="flex items-center gap-2 text-sm">
            <span className="min-w-0 flex-1 truncate">{row.name}</span>
            <Input
              aria-label={`Cantidad mínima de ${row.name}`}
              className="h-8 w-20 text-right"
              inputMode="decimal"
              value={row.minQty}
              onChange={(event) => update(index, { minQty: event.target.value })}
            />
            <Input
              aria-label={`Precio de ${row.name}`}
              className="h-8 w-24 text-right"
              inputMode="decimal"
              placeholder="Precio"
              value={row.price}
              onChange={(event) => update(index, { price: event.target.value })}
            />
            <Button
              type="button"
              variant="ghost"
              size="icon"
              className="size-7"
              aria-label={`Quitar ${row.name}`}
              onClick={() => setRows((list) => list.filter((_, i) => i !== index))}
            >
              <Trash2 className="size-3.5" />
            </Button>
          </div>
        ))}
        {rows.length === 0 && (
          <p className="text-sm text-muted-foreground">
            Aún no hay precios. Agrega productos con su cantidad mínima y precio.
          </p>
        )}
      </div>
    </FormDialog>
  );
}
