import { createFileRoute } from "@tanstack/react-router";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus, Printer, Truck } from "lucide-react";
import { useState } from "react";

import { EmptyState } from "@/components/common/empty-state";
import { ErrorState } from "@/components/common/error-state";
import { FormDialog } from "@/components/common/form-dialog";
import { StatusChip } from "@/components/common/status-chip";
import { DashboardLayout } from "@/components/dashboard/dashboard-layout";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { useResourceList } from "@/features/organization/resources";
import { useGuardedMutation } from "@/hooks/use-guarded-mutation";
import { api } from "@/lib/api";
import { formatPen } from "@/lib/money";

type ShipmentStatus =
  | "pending"
  | "dispatched"
  | "in_transit"
  | "at_destination_office"
  | "delivered"
  | "returned"
  | "incident";

/** Fila del listado de envíos. */
interface ShipmentRow {
  id: string;
  number: number;
  status: ShipmentStatus;
  recipient_name: string;
  destination_office: string | null;
  pickup_code: string | null;
  carrier_order_number: string | null;
  freight_amount: string;
  freight_status: string;
  cod_amount: string;
  packages_count: number;
  carrier_name: string;
}

/** Agencia mínima. */
interface Carrier {
  id: string;
  name: string;
  status: string;
}

const STATUS: Record<
  ShipmentStatus,
  { label: string; tone: "info" | "warning" | "success" | "neutral" | "danger" }
> = {
  pending: { label: "Por despachar", tone: "info" },
  dispatched: { label: "Despachado", tone: "warning" },
  in_transit: { label: "En tránsito", tone: "warning" },
  at_destination_office: { label: "En agencia destino", tone: "success" },
  delivered: { label: "Entregado", tone: "neutral" },
  returned: { label: "Devuelto", tone: "danger" },
  incident: { label: "Incidencia", tone: "danger" },
};

const NEXT: Partial<Record<ShipmentStatus, { to: ShipmentStatus; label: string }>> = {
  pending: { to: "dispatched", label: "Despachar" },
  dispatched: { to: "in_transit", label: "En tránsito" },
  in_transit: { to: "at_destination_office", label: "Llegó a destino" },
  at_destination_office: { to: "delivered", label: "Entregado" },
};

/** Datos del formulario de nuevo envío. */
interface ShipmentForm {
  carrier_id: string;
  recipient_name: string;
  recipient_phone: string;
  destination_office: string;
  freight_amount: string;
  cod_amount: string;
  packages_count: string;
  content_description: string;
}

const emptyForm: ShipmentForm = {
  carrier_id: "",
  recipient_name: "",
  recipient_phone: "",
  destination_office: "",
  freight_amount: "",
  cod_amount: "",
  packages_count: "1",
  content_description: "",
};

/**
 * Envíos: listado con cambio de estado, alta y rótulo para pegar en el bulto.
 */
function ShipmentsPage() {
  const queryClient = useQueryClient();
  const list = useQuery({
    queryKey: ["shipments"],
    queryFn: async () => (await api<{ data: ShipmentRow[] }>("/shipments")).data,
  });
  const carriers = useResourceList<Carrier>("carriers");
  const activeCarriers = (carriers.data ?? []).filter((c) => c.status === "active");
  const refresh = () => void queryClient.invalidateQueries({ queryKey: ["shipments"] });

  const [open, setOpen] = useState(false);
  const [form, setForm] = useState<ShipmentForm>(emptyForm);
  const [label, setLabel] = useState<ShipmentRow | null>(null);

  const create = useGuardedMutation({
    mutationFn: (input: ShipmentForm, { signal, idempotencyKey }) =>
      api("/shipments", {
        method: "POST",
        signal,
        idempotencyKey,
        body: {
          carrier_id: input.carrier_id || activeCarriers[0]?.id,
          recipient_name: input.recipient_name,
          recipient_phone: input.recipient_phone || null,
          destination_office: input.destination_office || null,
          freight_amount: Number(input.freight_amount) || 0,
          cod_amount: Number(input.cod_amount) || 0,
          freight_status: Number(input.cod_amount) > 0 ? "collect_on_delivery" : "prepaid",
          packages_count: Number(input.packages_count) || 1,
          content_description: input.content_description || null,
        },
      }),
    successMessage: "Envío registrado",
    onSuccess: () => {
      setOpen(false);
      refresh();
    },
  });

  const changeStatus = useGuardedMutation({
    mutationFn: (input: { id: string; status: ShipmentStatus }, { signal }) =>
      api(`/shipments/${input.id}/status`, {
        method: "POST",
        body: { status: input.status },
        signal,
      }),
    successMessage: "Estado actualizado",
    onSuccess: refresh,
  });

  const field = (name: keyof ShipmentForm, text: string, hint?: string) => (
    <div className="grid gap-2">
      <Label htmlFor={`s-${name}`}>{text}</Label>
      <Input
        id={`s-${name}`}
        value={form[name]}
        placeholder={hint}
        onChange={(event) => setForm({ ...form, [name]: event.target.value })}
        aria-invalid={create.fieldErrors[name] !== undefined}
      />
      {create.fieldErrors[name]?.[0] && (
        <p className="text-xs text-destructive">{create.fieldErrors[name][0]}</p>
      )}
    </div>
  );

  return (
    <DashboardLayout
      title="Envíos"
      description="Lo que despachas por agencia o reparto, con su seguimiento."
      actions={
        <Button
          onClick={() => {
            create.reset();
            setForm(emptyForm);
            setOpen(true);
          }}
        >
          <Plus /> Nuevo envío
        </Button>
      }
    >
      {list.isPending && <Skeleton className="h-24 w-full" />}
      {list.isError && <ErrorState onRetry={() => void list.refetch()} />}
      {list.data?.length === 0 && (
        <EmptyState
          icon={Truck}
          title="Aún no hay envíos"
          description="Registra un envío para llevar el control de lo que despachas."
        />
      )}
      {list.data && list.data.length > 0 && (
        <Card className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Envío</TableHead>
                <TableHead>Destinatario</TableHead>
                <TableHead>Agencia</TableHead>
                <TableHead>Estado</TableHead>
                <TableHead className="text-right">Flete</TableHead>
                <TableHead className="text-right">Cobro destino</TableHead>
                <TableHead className="w-56" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {list.data.map((row) => {
                const next = NEXT[row.status];
                return (
                  <TableRow key={row.id}>
                    <TableCell className="font-medium tabular-nums">
                      #{String(row.number).padStart(5, "0")}
                    </TableCell>
                    <TableCell>
                      {row.recipient_name}
                      {row.destination_office && (
                        <span className="block text-xs text-muted-foreground">
                          {row.destination_office}
                        </span>
                      )}
                    </TableCell>
                    <TableCell>{row.carrier_name}</TableCell>
                    <TableCell>
                      <StatusChip label={STATUS[row.status].label} tone={STATUS[row.status].tone} />
                    </TableCell>
                    <TableCell className="text-right tabular-nums">
                      {formatPen(row.freight_amount)}
                    </TableCell>
                    <TableCell className="text-right tabular-nums">
                      {Number(row.cod_amount) > 0 ? formatPen(row.cod_amount) : "—"}
                    </TableCell>
                    <TableCell className="space-x-1 text-right">
                      <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Imprimir rótulo"
                        onClick={() => setLabel(row)}
                      >
                        <Printer className="size-4" />
                      </Button>
                      {next && (
                        <Button
                          size="sm"
                          variant="outline"
                          disabled={changeStatus.isProcessing}
                          onClick={() => void changeStatus.run({ id: row.id, status: next.to })}
                        >
                          {next.label}
                        </Button>
                      )}
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
        </Card>
      )}

      <FormDialog
        open={open}
        onOpenChange={setOpen}
        title="Nuevo envío"
        submitLabel="Registrar envío"
        processingLabel="Registrando…"
        processing={create.isProcessing}
        slow={create.isSlow}
        onSubmit={() => void create.run(form)}
      >
        <div className="grid gap-2">
          <Label htmlFor="s-carrier">Agencia</Label>
          <select
            id="s-carrier"
            value={form.carrier_id || activeCarriers[0]?.id || ""}
            onChange={(event) => setForm({ ...form, carrier_id: event.target.value })}
            className="h-9 rounded-md border border-input bg-background px-3 text-sm"
          >
            {activeCarriers.map((carrier) => (
              <option key={carrier.id} value={carrier.id}>
                {carrier.name}
              </option>
            ))}
          </select>
          {activeCarriers.length === 0 && (
            <p className="text-xs text-muted-foreground">
              Primero crea una agencia en Envíos → Agencias.
            </p>
          )}
        </div>
        {field("recipient_name", "Quién recibe")}
        <div className="grid grid-cols-2 gap-3">
          {field("recipient_phone", "Teléfono")}
          {field("destination_office", "Agencia o dirección de destino", "Shalom Arequipa")}
        </div>
        <div className="grid grid-cols-3 gap-3">
          {field("freight_amount", "Flete (S/)")}
          {field("cod_amount", "Cobro en destino (S/)")}
          {field("packages_count", "Bultos")}
        </div>
        {field("content_description", "Contenido")}
      </FormDialog>

      <FormDialog
        open={label !== null}
        onOpenChange={(value) => !value && setLabel(null)}
        title="Rótulo del bulto"
        description="Imprime y pega en el paquete."
        size="sm"
        submitLabel="Imprimir"
        processingLabel="Imprimiendo…"
        processing={false}
        slow={false}
        onSubmit={() => window.print()}
      >
        {label && (
          <div className="print-area space-y-1 rounded-md border-2 border-foreground p-4 text-sm">
            <p className="text-xs uppercase text-muted-foreground">Destinatario</p>
            <p className="text-lg font-bold">{label.recipient_name}</p>
            {label.destination_office && <p>{label.destination_office}</p>}
            <p className="pt-2 text-xs uppercase text-muted-foreground">Agencia</p>
            <p className="font-semibold">{label.carrier_name}</p>
            {label.pickup_code && <p>Código: {label.pickup_code}</p>}
            <p>
              Envío #{String(label.number).padStart(5, "0")} · {label.packages_count} bulto(s)
            </p>
            {Number(label.cod_amount) > 0 && (
              <p className="pt-2 text-base font-bold">COBRAR: {formatPen(label.cod_amount)}</p>
            )}
          </div>
        )}
      </FormDialog>
    </DashboardLayout>
  );
}

export const Route = createFileRoute("/_app/envios/")({
  component: ShipmentsPage,
});
