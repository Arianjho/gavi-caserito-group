import { useQuery } from "@tanstack/react-query";
import { useState } from "react";

import { FormDialog } from "@/components/common/form-dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { useGuardedMutation } from "@/hooks/use-guarded-mutation";
import { api } from "@/lib/api";
import { formatPen, roundMoney } from "@/lib/money";

/** Línea de compra con lo ya devuelto. */
interface PurchaseLine {
  id: string;
  description: string;
  qty: string;
  unit_cost: string;
  qty_returned: string;
}

/** Propiedades de {@link PurchaseReturnDialog}. */
export interface PurchaseReturnDialogProps {
  purchaseId: string;
  onClose: () => void;
  onDone: () => void;
}

/**
 * Devolución de mercadería al proveedor: baja el stock y reduce lo que se
 * debe (o deja saldo a favor si ya estaba pagado).
 */
export function PurchaseReturnDialog({ purchaseId, onClose, onDone }: PurchaseReturnDialogProps) {
  const purchase = useQuery({
    queryKey: ["purchases", purchaseId],
    queryFn: async () =>
      (await api<{ data: { number: number; lines: PurchaseLine[] } }>(`/purchases/${purchaseId}`))
        .data,
  });
  const [qtys, setQtys] = useState<Record<string, string>>({});
  const [reason, setReason] = useState("");

  const lines = (purchase.data?.lines ?? []).map((line) => ({
    line,
    left: Number(line.qty) - Number(line.qty_returned),
  }));
  const chosen = lines
    .map(({ line }) => ({ line, qty: Number(qtys[line.id] ?? 0) }))
    .filter((item) => item.qty > 0);
  const estimate = roundMoney(
    chosen.reduce((sum, item) => sum + item.qty * Number(item.line.unit_cost), 0),
  );

  const save = useGuardedMutation({
    mutationFn: (_input: void, { signal, idempotencyKey }) =>
      api(`/purchases/${purchaseId}/returns`, {
        method: "POST",
        signal,
        idempotencyKey,
        body: {
          reason,
          lines: chosen.map((item) => ({ line_id: item.line.id, qty: item.qty })),
        },
      }),
    successMessage: "Devolución registrada",
    onSuccess: onDone,
  });

  return (
    <FormDialog
      open
      onOpenChange={(open) => !open && onClose()}
      title={purchase.data ? `Devolver compra #${purchase.data.number}` : "Devolver compra"}
      description="El stock sale del almacén y se reduce lo que debes al proveedor."
      size="lg"
      submitLabel={`Devolver ${formatPen(estimate)}`}
      processingLabel="Registrando…"
      processing={save.isProcessing}
      slow={save.isSlow}
      onSubmit={() => (chosen.length > 0 && reason.trim() !== "" ? void save.run() : undefined)}
    >
      <div className="space-y-2">
        {lines.map(({ line, left }) => (
          <div key={line.id} className="flex items-center gap-2 text-sm">
            <span className="min-w-0 flex-1 truncate">{line.description}</span>
            <span className="text-xs text-muted-foreground">
              Comprado {Number(line.qty)} · por devolver {left}
            </span>
            <Input
              aria-label={`Cantidad a devolver de ${line.description}`}
              className="h-8 w-20 text-right"
              inputMode="decimal"
              disabled={left <= 0}
              value={qtys[line.id] ?? ""}
              onChange={(event) => setQtys({ ...qtys, [line.id]: event.target.value })}
            />
          </div>
        ))}
        {save.fieldErrors["lines"]?.[0] && (
          <p className="text-xs text-destructive">{save.fieldErrors["lines"][0]}</p>
        )}
      </div>
      <div className="grid gap-2">
        <Label htmlFor="pret-reason">Motivo</Label>
        <Input
          id="pret-reason"
          value={reason}
          onChange={(event) => setReason(event.target.value)}
          aria-invalid={save.fieldErrors["reason"] !== undefined}
        />
        {save.fieldErrors["reason"]?.[0] && (
          <p className="text-xs text-destructive">{save.fieldErrors["reason"][0]}</p>
        )}
      </div>
    </FormDialog>
  );
}
