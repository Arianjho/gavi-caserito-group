import { useState } from "react";

import { ActionButton } from "@/components/common/action-button";
import { StatusChip } from "@/components/common/status-chip";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
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
import { useInvalidateResource } from "@/features/organization/resources";
import { useGuardedMutation } from "@/hooks/use-guarded-mutation";
import { api } from "@/lib/api";

/** Variante de un producto. */
export interface VariantRow {
  id: string;
  name: string | null;
  sku: string | null;
  status: string;
  is_default: boolean;
  sale_price: string | null;
  barcodes: string[];
}

/** Propiedades de {@link VariantsDialog}. */
export interface VariantsDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  productId: string;
  productName: string;
  variants: VariantRow[];
}

/** Convierte "S, M, L" en una lista sin vacíos ni repetidos. */
function parseValues(text: string): string[] {
  return [
    ...new Set(
      text
        .split(",")
        .map((value) => value.trim())
        .filter(Boolean),
    ),
  ];
}

/**
 * Variantes de un producto: lista con activar/desactivar y generador de
 * combinaciones (por ejemplo talla × color). Las combinaciones que ya
 * existen se omiten.
 */
export function VariantsDialog({
  open,
  onOpenChange,
  productId,
  productName,
  variants,
}: VariantsDialogProps) {
  const invalidate = useInvalidateResource("items");
  const [axisA, setAxisA] = useState({ name: "Talla", values: "" });
  const [axisB, setAxisB] = useState({ name: "Color", values: "" });

  const generate = useGuardedMutation({
    mutationFn: (_input: void, { signal }) => {
      const axes = [axisA, axisB]
        .map((axis) => ({ name: axis.name.trim(), values: parseValues(axis.values) }))
        .filter((axis) => axis.name !== "" && axis.values.length > 0);
      return api(`/items/${productId}/variants/generate`, {
        method: "POST",
        signal,
        body: { axes },
      });
    },
    successMessage: "Variantes generadas",
    onSuccess: () => {
      setAxisA({ ...axisA, values: "" });
      setAxisB({ ...axisB, values: "" });
      void invalidate();
    },
  });

  const toggle = useGuardedMutation({
    mutationFn: (input: { id: string; active: boolean }, { signal }) =>
      api(`/variants/${input.id}/active`, {
        method: "PATCH",
        signal,
        body: { active: input.active },
      }),
    successMessage: "Variante actualizada",
    onSuccess: () => void invalidate(),
  });

  const hasAxis = parseValues(axisA.values).length > 0 || parseValues(axisB.values).length > 0;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Variantes de {productName}</DialogTitle>
          <DialogDescription>
            Cada variante tiene su propio stock, SKU, código de barras y precio.
          </DialogDescription>
        </DialogHeader>

        <div className="max-h-64 overflow-auto rounded-md border border-border">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Variante</TableHead>
                <TableHead>SKU</TableHead>
                <TableHead className="text-right">Precio</TableHead>
                <TableHead>Estado</TableHead>
                <TableHead className="w-28" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {variants.map((variant) => (
                <TableRow key={variant.id}>
                  <TableCell className="font-medium">{variant.name ?? "Estándar"}</TableCell>
                  <TableCell className="tabular-nums">{variant.sku ?? "—"}</TableCell>
                  <TableCell className="text-right tabular-nums">
                    {variant.sale_price ? `S/ ${Number(variant.sale_price).toFixed(2)}` : "—"}
                  </TableCell>
                  <TableCell>
                    <StatusChip
                      label={variant.status === "active" ? "Activa" : "Inactiva"}
                      tone={variant.status === "active" ? "success" : "neutral"}
                    />
                  </TableCell>
                  <TableCell className="text-right">
                    <Button
                      variant="ghost"
                      size="sm"
                      disabled={toggle.isProcessing}
                      onClick={() =>
                        void toggle.run({ id: variant.id, active: variant.status !== "active" })
                      }
                    >
                      {variant.status === "active" ? "Desactivar" : "Activar"}
                    </Button>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>

        <section className="space-y-3 rounded-md border border-border p-3">
          <h3 className="text-sm font-semibold">Generar combinaciones</h3>
          <p className="text-xs text-muted-foreground">
            Escribe los valores separados por coma. Hereda precio y costo del producto.
          </p>
          {[
            { axis: axisA, set: setAxisA, id: "a" },
            { axis: axisB, set: setAxisB, id: "b" },
          ].map(({ axis, set, id }) => (
            <div key={id} className="grid grid-cols-[8rem_1fr] items-end gap-2">
              <div className="grid gap-1">
                <Label htmlFor={`axis-${id}-name`}>Atributo</Label>
                <Input
                  id={`axis-${id}-name`}
                  value={axis.name}
                  onChange={(event) => set({ ...axis, name: event.target.value })}
                />
              </div>
              <div className="grid gap-1">
                <Label htmlFor={`axis-${id}-values`}>Valores</Label>
                <Input
                  id={`axis-${id}-values`}
                  placeholder={id === "a" ? "S, M, L" : "Rojo, Azul"}
                  value={axis.values}
                  onChange={(event) => set({ ...axis, values: event.target.value })}
                />
              </div>
            </div>
          ))}
          <ActionButton
            disabled={!hasAxis}
            processing={generate.isProcessing}
            processingLabel="Generando…"
            onClick={() => void generate.run()}
          >
            Generar variantes
          </ActionButton>
        </section>
      </DialogContent>
    </Dialog>
  );
}
