import { useQuery } from "@tanstack/react-query";
import { useState } from "react";

import { FormDialog } from "@/components/common/form-dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { useGuardedMutation } from "@/hooks/use-guarded-mutation";
import { api } from "@/lib/api";

/** Cómo se expresa cada motivo del catálogo 09 de SUNAT. */
type Mode = "full" | "lines" | "amount";

const REASONS: { code: string; label: string; mode: Mode }[] = [
  { code: "01", label: "Anulación de la operación", mode: "full" },
  { code: "06", label: "Devolución de mercadería (total o parcial)", mode: "lines" },
  { code: "04", label: "Descuento global posterior", mode: "amount" },
  { code: "05", label: "Descuento por ítem", mode: "amount" },
  { code: "07", label: "Bonificación posterior", mode: "amount" },
  { code: "08", label: "Disminución en el valor", mode: "amount" },
  { code: "02", label: "Anulación por error en el RUC", mode: "full" },
  { code: "03", label: "Corrección por error en la descripción", mode: "full" },
  { code: "09", label: "Otros (por ítems)", mode: "lines" },
];

const METHODS = [
  { value: "cash", label: "Efectivo" },
  { value: "yape", label: "Yape" },
  { value: "plin", label: "Plin" },
  { value: "card", label: "Tarjeta" },
  { value: "transfer", label: "Transferencia" },
  { value: "other", label: "Otro" },
];

/** Línea de la venta que origina el comprobante. */
interface SaleLine {
  id: string;
  description: string;
  qty: string;
}

/** Propiedades de {@link CreditNoteDialog}. */
export interface CreditNoteDialogProps {
  /** Comprobante fiscal que se modifica. */
  documentId: string;
  /** Venta de origen (para listar sus ítems). */
  saleId: string;
  /** Texto del comprobante, por ejemplo `B001-00000123`. */
  label: string;
  onClose: () => void;
  onDone: () => void;
}

/**
 * Emite una nota de crédito sobre un comprobante aceptado.
 *
 * Los motivos totales no piden nada más; los parciales piden cantidades por
 * ítem; los de descuento piden un monto. En anulación y devolución puede
 * reingresar el stock y devolver el dinero en el mismo paso.
 */
export function CreditNoteDialog({
  documentId,
  saleId,
  label,
  onClose,
  onDone,
}: CreditNoteDialogProps) {
  const sale = useQuery({
    queryKey: ["sales", saleId],
    queryFn: async () => (await api<{ data: { lines: SaleLine[] } }>(`/sales/${saleId}`)).data,
  });
  const [code, setCode] = useState("01");
  const [text, setText] = useState("");
  const [amount, setAmount] = useState("");
  const [qtys, setQtys] = useState<Record<string, string>>({});
  const [restock, setRestock] = useState(false);
  const [method, setMethod] = useState("cash");

  const reason = REASONS.find((item) => item.code === code) ?? REASONS[0];
  const mode = reason?.mode ?? "full";
  const canRestock = code === "01" || code === "06";
  const chosen = (sale.data?.lines ?? [])
    .map((line) => ({ line_id: line.id, qty: Number(qtys[line.id] ?? 0) }))
    .filter((item) => item.qty > 0);

  const save = useGuardedMutation({
    mutationFn: (_input: void, { signal, idempotencyKey }) =>
      api(`/fiscal/documents/${documentId}/credit-notes`, {
        method: "POST",
        signal,
        idempotencyKey,
        body: {
          reason_code: code,
          reason_text: text,
          ...(mode === "lines" ? { lines: chosen } : {}),
          ...(mode === "amount" ? { amount: Number(amount) } : {}),
          ...(restock && canRestock ? { refund_method: method } : {}),
        },
      }),
    successMessage: "Nota de crédito enviada",
    onSuccess: onDone,
  });

  const ready =
    text.trim() !== "" &&
    (mode === "full" ||
      (mode === "lines" && chosen.length > 0) ||
      (mode === "amount" && Number(amount) > 0));
  const error = (name: string) =>
    save.fieldErrors[name]?.[0] ? (
      <p className="text-xs text-destructive">{save.fieldErrors[name][0]}</p>
    ) : null;

  return (
    <FormDialog
      open
      onOpenChange={(open) => !open && onClose()}
      title={`Nota de crédito sobre ${label}`}
      description="Corrige el comprobante ante SUNAT sin anularlo."
      size="lg"
      submitLabel="Emitir nota de crédito"
      processingLabel="Enviando…"
      processing={save.isProcessing}
      slow={save.isSlow}
      onSubmit={() => (ready ? void save.run() : undefined)}
    >
      <div className="grid gap-2">
        <Label htmlFor="nc-reason">Motivo</Label>
        <select
          id="nc-reason"
          value={code}
          onChange={(event) => setCode(event.target.value)}
          className="h-9 rounded-md border border-input bg-background px-3 text-sm"
        >
          {REASONS.map((item) => (
            <option key={item.code} value={item.code}>
              {item.code} · {item.label}
            </option>
          ))}
        </select>
        {error("reason_code")}
      </div>
      {mode === "lines" && (
        <div className="space-y-2">
          <p className="text-xs text-muted-foreground">Cantidad a acreditar por ítem:</p>
          {(sale.data?.lines ?? []).map((line) => (
            <div key={line.id} className="flex items-center gap-2 text-sm">
              <span className="min-w-0 flex-1 truncate">{line.description}</span>
              <span className="text-xs text-muted-foreground">Vendidas {Number(line.qty)}</span>
              <Input
                aria-label={`Cantidad a acreditar de ${line.description}`}
                className="h-8 w-20 text-right"
                inputMode="decimal"
                value={qtys[line.id] ?? ""}
                onChange={(event) => setQtys({ ...qtys, [line.id]: event.target.value })}
              />
            </div>
          ))}
          {error("lines")}
        </div>
      )}
      {mode === "full" && (
        <p className="text-xs text-muted-foreground">
          Revierte todos los ítems del comprobante. Si ya acreditaste algo, usa el motivo 06.
        </p>
      )}
      {mode === "amount" && (
        <div className="grid gap-2">
          <Label htmlFor="nc-amount">Monto con IGV (S/)</Label>
          <Input
            id="nc-amount"
            inputMode="decimal"
            value={amount}
            onChange={(event) => setAmount(event.target.value)}
            aria-invalid={save.fieldErrors["amount"] !== undefined}
          />
          {error("amount")}
        </div>
      )}
      <div className="grid gap-2">
        <Label htmlFor="nc-text">Descripción del motivo</Label>
        <Input
          id="nc-text"
          maxLength={250}
          value={text}
          onChange={(event) => setText(event.target.value)}
          aria-invalid={save.fieldErrors["reason_text"] !== undefined}
        />
        {error("reason_text")}
      </div>
      {canRestock && (
        <div className="space-y-2 rounded-md border p-3">
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={restock}
              onChange={(event) => setRestock(event.target.checked)}
            />
            Reingresar el stock y devolver el dinero
          </label>
          {restock && (
            <div className="grid gap-2">
              <Label htmlFor="nc-method">Devolver el dinero por</Label>
              <select
                id="nc-method"
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
          )}
        </div>
      )}
    </FormDialog>
  );
}
