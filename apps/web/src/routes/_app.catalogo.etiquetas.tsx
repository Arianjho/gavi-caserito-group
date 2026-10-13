import { createFileRoute } from "@tanstack/react-router";
import { useQuery } from "@tanstack/react-query";
import JsBarcode from "jsbarcode";
import { Printer, Trash2 } from "lucide-react";
import { useEffect, useRef, useState } from "react";

import { DashboardLayout } from "@/components/dashboard/dashboard-layout";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { api } from "@/lib/api";
import { formatPen } from "@/lib/money";
import { expandVariants } from "@/lib/variants";

/** Producto con variantes devuelto por la búsqueda. */
interface Product {
  id: string;
  name: string;
  variants: {
    id: string;
    name: string | null;
    status: string;
    sku: string | null;
    sale_price: string | null;
    barcodes: string[];
  }[];
}

/** Etiqueta a imprimir. */
interface LabelItem {
  variantId: string;
  name: string;
  code: string;
  price: string | null;
  copies: number;
}

/** Código de barras de una etiqueta, dibujado en SVG. */
function Barcode({ code }: { code: string }) {
  const ref = useRef<SVGSVGElement>(null);
  useEffect(() => {
    if (!ref.current) return;
    const isEan = /^\d{13}$/.test(code);
    try {
      JsBarcode(ref.current, code, {
        format: isEan ? "EAN13" : "CODE128",
        height: 36,
        width: 1.4,
        fontSize: 11,
        margin: 0,
        displayValue: true,
      });
    } catch {
      // Un EAN-13 con dígito verificador inválido se imprime como CODE128.
      JsBarcode(ref.current, code, { format: "CODE128", height: 36, width: 1.4, margin: 0 });
    }
  }, [code]);
  return <svg ref={ref} className="max-w-full" />;
}

/**
 * Impresión de etiquetas con código de barras: se eligen productos y
 * copias, se ajusta el tamaño y se imprime desde el navegador.
 */
function LabelsPage() {
  const [term, setTerm] = useState("");
  const [labels, setLabels] = useState<LabelItem[]>([]);
  const [width, setWidth] = useState(50);
  const [height, setHeight] = useState(30);
  const [showPrice, setShowPrice] = useState(true);

  const results = useQuery({
    queryKey: ["label-search", term],
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

  const add = (product: Product) => {
    const variant = product.variants[0];
    const code = variant?.barcodes[0] ?? variant?.sku;
    if (!variant || !code) return;
    setLabels((current) =>
      current.some((label) => label.variantId === variant.id)
        ? current
        : [
            ...current,
            {
              variantId: variant.id,
              name: product.name,
              code,
              price: variant.sale_price,
              copies: 1,
            },
          ],
    );
    setTerm("");
  };

  const setCopies = (variantId: string, copies: number) =>
    setLabels((current) =>
      current.map((label) =>
        label.variantId === variantId ? { ...label, copies: Math.max(1, copies) } : label,
      ),
    );

  const sheet = labels.flatMap((label) =>
    Array.from({ length: label.copies }, (_, index) => ({
      ...label,
      key: `${label.variantId}-${index}`,
    })),
  );

  return (
    <DashboardLayout
      title="Etiquetas"
      description="Imprime códigos de barras para tus productos."
      actions={
        <Button disabled={sheet.length === 0} onClick={() => window.print()}>
          <Printer /> Imprimir {sheet.length > 0 ? `(${sheet.length})` : ""}
        </Button>
      }
    >
      <style>{`
        @media print {
          body * { visibility: hidden; }
          #label-sheet, #label-sheet * { visibility: visible; }
          #label-sheet { position: absolute; left: 0; top: 0; }
        }
      `}</style>
      <div className="max-w-xl print:hidden">
        <Card>
          <CardContent className="space-y-4 p-4">
            <div className="relative grid gap-2">
              <Label htmlFor="label-search">Agregar producto</Label>
              <Input
                id="label-search"
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
                        onClick={() => add(item)}
                      >
                        {item.name}
                        {!(item.variants[0]?.barcodes[0] ?? item.variants[0]?.sku) && (
                          <span className="ml-2 text-xs text-muted-foreground">(sin código)</span>
                        )}
                      </button>
                    </li>
                  ))}
                </ul>
              )}
            </div>

            <div className="grid grid-cols-3 gap-3">
              <div className="grid gap-1">
                <Label htmlFor="label-w">Ancho (mm)</Label>
                <Input
                  id="label-w"
                  type="number"
                  min={20}
                  value={width}
                  onChange={(event) => setWidth(Number(event.target.value) || 50)}
                />
              </div>
              <div className="grid gap-1">
                <Label htmlFor="label-h">Alto (mm)</Label>
                <Input
                  id="label-h"
                  type="number"
                  min={15}
                  value={height}
                  onChange={(event) => setHeight(Number(event.target.value) || 30)}
                />
              </div>
              <label className="flex items-end gap-2 pb-2 text-sm">
                <input
                  type="checkbox"
                  checked={showPrice}
                  onChange={(event) => setShowPrice(event.target.checked)}
                />
                Precio
              </label>
            </div>

            <ul className="divide-y divide-border">
              {labels.map((label) => (
                <li key={label.variantId} className="flex items-center gap-2 py-2 text-sm">
                  <span className="min-w-0 flex-1 truncate">{label.name}</span>
                  <Input
                    aria-label={`Copias de ${label.name}`}
                    className="h-8 w-16 text-right"
                    type="number"
                    min={1}
                    value={label.copies}
                    onChange={(event) => setCopies(label.variantId, Number(event.target.value))}
                  />
                  <Button
                    variant="ghost"
                    size="icon"
                    className="size-7"
                    aria-label={`Quitar ${label.name}`}
                    onClick={() =>
                      setLabels((current) => current.filter((l) => l.variantId !== label.variantId))
                    }
                  >
                    <Trash2 className="size-3.5" />
                  </Button>
                </li>
              ))}
            </ul>
            {labels.length === 0 && (
              <p className="text-sm text-muted-foreground">
                Busca un producto con código de barras o SKU para armar la hoja.
              </p>
            )}
          </CardContent>
        </Card>
      </div>

      <div
        id="label-sheet"
        className="mt-4 flex flex-wrap gap-2 print:mt-0 print:gap-0"
        aria-label="Hoja de etiquetas"
      >
        {sheet.map((label) => (
          <div
            key={label.key}
            className="flex flex-col items-center justify-center overflow-hidden border border-dashed border-border bg-white p-1 text-center text-black print:border-0"
            style={{ width: `${width}mm`, height: `${height}mm` }}
          >
            <span className="w-full truncate text-[10px] font-semibold leading-tight">
              {label.name}
            </span>
            <Barcode code={label.code} />
            {showPrice && label.price && (
              <span className="text-xs font-bold">{formatPen(label.price)}</span>
            )}
          </div>
        ))}
      </div>
    </DashboardLayout>
  );
}

export const Route = createFileRoute("/_app/catalogo/etiquetas")({
  component: LabelsPage,
});
