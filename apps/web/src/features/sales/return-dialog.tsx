import { useQuery } from "@tanstack/react-query";
import { useState } from "react";

import { FormDialog } from "@/components/common/form-dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import type { SaleDetail } from "@/features/sales/ticket";
import { useGuardedMutation } from "@/hooks/use-guarded-mutation";
import { api } from "@/lib/api";
import { formatPen, roundMoney } from "@/lib/money";

/** Devolución previa de una venta (solo lo necesario para calcular lo pendiente). */
interface PreviousReturn {
  lines: { sale_line_id: string; qty: string }[];
}

/** Propiedades de {@link ReturnDialog}. */
export interface ReturnDialogProps {
  saleId: string;
  onClose: () => void;
  onDone: () => void;
}

const METHODS = [
  { value: "cash", label: "Efectivo" },
  { value: "yape", label: "Yape" },
  { value: "plin", label: "Plin" },
  { value: "transfer", label: "Transferencia" },
  { value: "card", label: "Tarjeta" },
  { value: "other", label: "Otro" },
];

/**
 * Devolución de una venta: se elige cuánto devuelve el cliente de cada
 * línea, el medio de reembolso y el motivo. Reingresa el stock y, si es en
 * efectivo, sale del turno de caja abierto.
 */
export function ReturnDialog({ saleId, onClose, onDone }: ReturnDialogProps) {
  const sale = useQuery({
    queryKey: ["sales", saleId],
    queryFn: async () => (await api<{ data: SaleDetail }>(`/sales/${saleId}`)).data,
  });
  const previous = useQuery({
    queryKey: ["sales", saleId, "returns"],
    queryFn: async () => (await api<{ data: PreviousReturn[] }>(`/sales/${saleId}/returns`)).data,
  });

  const [qtys, setQtys] = useState<Record<string, string>>({});
  const [method, setMethod] = useState("cash");
  const [reason, setReason] = useState("");

  const returnedBefore = (lineId: string): number =>
    (previous.data ?? [])
      .flatMap((ret) => ret.lines)
      .filter((line) => line.sale_line_id === lineId)
      .reduce((sum, line) => sum + Number(line.qty), 0);

  const lines = (sale.data?.lines ?? []).map((line) => ({
    line,
    left: Number(line.qty) - returnedBefore(line.id),
  }));
  const chosen = lines
    .map(({ line, left }) => ({ line, left, qty: Number(qtys[line.id] ?? 0) }))
    .filter((item) => item.qty > 0);
  const estimate = roundMoney(
    chosen.reduce(
      (sum, item) => sum + (Number(item.line.total) * item.qty) / Number(item.line.qty),
      0,
    ),
  );

  const save = useGuardedMutation({
    mutationFn: (_input: void, { signal, idempotencyKey }) =>
      api(`/sales/${saleId}/returns`, {
        method: "POST",
        signal,
        idempotencyKey,
        body: {
          refund_method: method,
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
      title={sale.data ? `Devolver ${sale.data.series}-${sale.data.number}` : "Devolver venta"}
      description="El stock vuelve al almacén y se devuelve el dinero al cliente."
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
              Vendidas {Number(line.qty)} · por devolver {left}
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
      <div className="grid grid-cols-2 gap-3">
        <div className="grid gap-2">
          <Label htmlFor="ret-method">Devolver el dinero por</Label>
          <select
            id="ret-method"
            value={method}
            onChange={(event) => setMethod(event.target.value)}
            className="h-9 rounded-md border border-input bg-background px-3 text-sm"
          >
            {METHODS.map((item) => (
              <option key={item.value} value={item.value}>
                {item.label}
              </option>
            ))}
          </select>
        </div>
        <div className="grid gap-2">
          <Label htmlFor="ret-reason">Motivo</Label>
          <Input
            id="ret-reason"
            value={reason}
            onChange={(event) => setReason(event.target.value)}
            aria-invalid={save.fieldErrors["reason"] !== undefined}
          />
          {save.fieldErrors["reason"]?.[0] && (
            <p className="text-xs text-destructive">{save.fieldErrors["reason"][0]}</p>
          )}
        </div>
      </div>
    </FormDialog>
  );
}
