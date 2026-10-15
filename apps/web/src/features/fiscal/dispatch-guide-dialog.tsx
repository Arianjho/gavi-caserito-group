import { useState } from "react";

import { FormDialog } from "@/components/common/form-dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { useGuardedMutation } from "@/hooks/use-guarded-mutation";
import { api } from "@/lib/api";

const REASONS = [
  { value: "01", label: "Venta" },
  { value: "04", label: "Traslado entre establecimientos" },
  { value: "02", label: "Compra" },
  { value: "13", label: "Otros" },
];

/** Propiedades de {@link DispatchGuideDialog}. */
export interface DispatchGuideDialogProps {
  /** Venta de la que salen ítems y destinatario; si falta, se piden a mano. */
  saleId?: string | undefined;
  /** Sucursal de partida cuando no hay venta. */
  branchId?: string | undefined;
  onClose: () => void;
  onDone: () => void;
}

/**
 * Guía de remisión remitente. El punto de partida sale de la sucursal; lo
 * demás se pide aquí. Con transporte público se informa al transportista; con
 * transporte privado, vehículo y conductor.
 */
export function DispatchGuideDialog({
  saleId,
  branchId,
  onClose,
  onDone,
}: DispatchGuideDialogProps) {
  const [form, setForm] = useState({
    transfer_reason: "01",
    transport_mode: "02",
    transfer_date: new Date().toISOString().slice(0, 10),
    weight: "",
    packages: "",
    arrival_ubigeo: "",
    arrival_address: "",
    recipient_doc_type: "DNI",
    recipient_doc: "",
    recipient_name: "",
    carrier_ruc: "",
    carrier_name: "",
    carrier_mtc: "",
    vehicle_plate: "",
    driver_doc: "",
    driver_first: "",
    driver_last: "",
    driver_license: "",
    item_description: "",
    item_qty: "1",
  });
  const set = (name: keyof typeof form, value: string) => setForm({ ...form, [name]: value });

  const body = () => {
    const withRecipient = form.recipient_doc.trim() !== "";
    return {
      ...(saleId ? { sales_document_id: saleId } : { branch_id: branchId }),
      transfer_reason: form.transfer_reason,
      transport_mode: form.transport_mode,
      transfer_date: form.transfer_date,
      weight: Number(form.weight),
      ...(form.packages ? { packages: Number(form.packages) } : {}),
      arrival_ubigeo: form.arrival_ubigeo,
      arrival_address: form.arrival_address,
      ...(withRecipient
        ? {
            recipient: {
              doc_type: form.recipient_doc_type,
              doc_number: form.recipient_doc,
              name: form.recipient_name,
            },
          }
        : {}),
      ...(form.transport_mode === "01"
        ? {
            carrier: {
              doc_number: form.carrier_ruc,
              name: form.carrier_name || null,
              mtc: form.carrier_mtc || null,
            },
          }
        : {
            vehicle_plate: form.vehicle_plate,
            driver: {
              doc_number: form.driver_doc,
              first_name: form.driver_first,
              last_name: form.driver_last,
              license: form.driver_license,
            },
          }),
      ...(saleId
        ? {}
        : { items: [{ description: form.item_description, qty: Number(form.item_qty) }] }),
    };
  };

  const save = useGuardedMutation({
    mutationFn: (_input: void, { signal, idempotencyKey }) =>
      api("/fiscal/dispatch-guides", { method: "POST", signal, idempotencyKey, body: body() }),
    successMessage: "Guía de remisión enviada",
    onSuccess: onDone,
  });

  const field = (name: keyof typeof form, label: string, options?: { hint?: string }) => (
    <div className="grid gap-2">
      <Label htmlFor={`g-${name}`}>{label}</Label>
      <Input
        id={`g-${name}`}
        value={form[name]}
        placeholder={options?.hint}
        onChange={(event) => set(name, event.target.value)}
        aria-invalid={save.fieldErrors[name] !== undefined}
      />
      {save.fieldErrors[name]?.[0] && (
        <p className="text-xs text-destructive">{save.fieldErrors[name][0]}</p>
      )}
    </div>
  );
  const select = (
    name: keyof typeof form,
    label: string,
    options: { value: string; label: string }[],
  ) => (
    <div className="grid gap-2">
      <Label htmlFor={`g-${name}`}>{label}</Label>
      <select
        id={`g-${name}`}
        value={form[name]}
        onChange={(event) => set(name, event.target.value)}
        className="h-9 rounded-md border border-input bg-background px-3 text-sm"
      >
        {options.map((item) => (
          <option key={item.value} value={item.value}>
            {item.label}
          </option>
        ))}
      </select>
    </div>
  );

  return (
    <FormDialog
      open
      onOpenChange={(open) => !open && onClose()}
      title="Guía de remisión"
      description="Parte desde la sucursal; ella debe tener dirección y ubigeo."
      size="lg"
      submitLabel="Emitir guía"
      processingLabel="Enviando…"
      processing={save.isProcessing}
      slow={save.isSlow}
      onSubmit={() => (Number(form.weight) > 0 ? void save.run() : undefined)}
    >
      <div className="grid grid-cols-2 gap-3">
        {select("transfer_reason", "Motivo del traslado", REASONS)}
        {select("transport_mode", "Transporte", [
          { value: "02", label: "Privado (vehículo propio)" },
          { value: "01", label: "Público (empresa de transporte)" },
        ])}
        {field("transfer_date", "Fecha de traslado")}
        {field("weight", "Peso total (kg)")}
        {field("packages", "Bultos (opcional)")}
      </div>
      <div className="grid grid-cols-3 gap-3">
        {field("arrival_ubigeo", "Ubigeo de llegada", { hint: "150101" })}
        <div className="col-span-2">{field("arrival_address", "Dirección de llegada")}</div>
      </div>
      <p className="text-xs text-muted-foreground">
        Destinatario: déjalo vacío para usar el cliente de la venta.
      </p>
      <div className="grid grid-cols-3 gap-3">
        {select("recipient_doc_type", "Documento", [
          { value: "DNI", label: "DNI" },
          { value: "RUC", label: "RUC" },
          { value: "CE", label: "Carné de extranjería" },
        ])}
        {field("recipient_doc", "Número")}
        {field("recipient_name", "Nombre o razón social")}
      </div>
      {form.transport_mode === "01" ? (
        <div className="grid grid-cols-3 gap-3">
          {field("carrier_ruc", "RUC del transportista")}
          {field("carrier_name", "Razón social")}
          {field("carrier_mtc", "N.º MTC (opcional)")}
        </div>
      ) : (
        <div className="grid grid-cols-2 gap-3">
          {field("vehicle_plate", "Placa del vehículo", { hint: "ABC-123" })}
          {field("driver_license", "Licencia del conductor")}
          {field("driver_doc", "DNI del conductor")}
          {field("driver_first", "Nombres")}
          {field("driver_last", "Apellidos")}
        </div>
      )}
      {!saleId && (
        <div className="grid grid-cols-3 gap-3">
          <div className="col-span-2">{field("item_description", "Bien trasladado")}</div>
          {field("item_qty", "Cantidad")}
        </div>
      )}
    </FormDialog>
  );
}
