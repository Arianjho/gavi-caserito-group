import { useQuery } from "@tanstack/react-query";
import { createFileRoute } from "@tanstack/react-router";
import { ExternalLink } from "lucide-react";

import { StatusChip } from "@/components/common/status-chip";
import { AuthShell } from "@/features/auth/auth-shell";
import { api } from "@/lib/api";
import { formatPen } from "@/lib/money";

/** Envío visible para el cliente. */
interface ShipmentView {
  number: number;
  status_label: string;
  carrier: string | null;
  destination: string | null;
  pickup_code: string | null;
  tracking_url: string | null;
  events: { label: string; note: string | null; at: string }[];
}

/** Seguimiento público de un pedido o de un envío. */
interface TrackingView extends ShipmentView {
  kind: "order" | "shipment";
  business: string;
  brand?: { color: string | null; tagline: string | null };
  status: string;
  total?: string;
  prepaid?: string;
  lines?: { description: string; qty: string; delivered: string }[];
  shipments?: ShipmentView[];
}

/** Línea de tiempo de un envío. */
function Timeline({ shipment }: { shipment: ShipmentView }) {
  return (
    <div className="space-y-2 rounded-md border border-border p-3 text-sm">
      <div className="flex items-center justify-between gap-2">
        <span className="font-medium">
          Envío #{shipment.number}
          {shipment.carrier ? ` · ${shipment.carrier}` : ""}
        </span>
        <StatusChip label={shipment.status_label} tone="info" />
      </div>
      {shipment.destination && (
        <p className="text-muted-foreground">Destino: {shipment.destination}</p>
      )}
      {shipment.pickup_code && (
        <p>
          Código de recojo: <strong>{shipment.pickup_code}</strong>
        </p>
      )}
      {shipment.tracking_url && (
        <a
          href={shipment.tracking_url}
          target="_blank"
          rel="noreferrer"
          className="inline-flex items-center gap-1 text-primary hover:underline"
        >
          Seguir en la agencia <ExternalLink className="size-3" />
        </a>
      )}
      <ol className="space-y-1 border-l border-border pl-3">
        {shipment.events.map((event, index) => (
          <li key={`${event.at}-${index}`}>
            <span className="font-medium">{event.label}</span>{" "}
            <span className="text-xs text-muted-foreground">
              {new Date(event.at).toLocaleString("es-PE")}
            </span>
            {event.note && <span className="block text-muted-foreground">{event.note}</span>}
          </li>
        ))}
      </ol>
    </div>
  );
}

/**
 * Página pública (sin ingreso) para que el cliente siga su pedido o envío
 * con el enlace que recibió por WhatsApp.
 */
function TrackingPage() {
  const { token } = Route.useParams();
  const view = useQuery({
    queryKey: ["public-tracking", token],
    queryFn: async () => (await api<{ data: TrackingView }>(`/public/tracking/${token}`)).data,
    retry: false,
  });

  if (view.isError) {
    return (
      <AuthShell
        title="No encontramos tu seguimiento"
        description="Revisa que el enlace esté completo."
      >
        <p className="text-sm text-muted-foreground">
          Si el problema continúa, escribe al negocio que te lo envió.
        </p>
      </AuthShell>
    );
  }
  if (!view.data) {
    return (
      <AuthShell title="Buscando tu pedido…" description="Un momento por favor.">
        <span className="sr-only">Cargando</span>
      </AuthShell>
    );
  }

  const data = view.data;
  return (
    <AuthShell
      title={data.kind === "order" ? `Pedido #${data.number}` : `Envío #${data.number}`}
      description={data.business}
    >
      <div className="space-y-4">
        {data.brand?.color && (
          <div className="h-1.5 rounded-full" style={{ backgroundColor: data.brand.color }} />
        )}
        {data.brand?.tagline && (
          <p className="text-sm italic text-muted-foreground">{data.brand.tagline}</p>
        )}
        <StatusChip
          label={data.status_label}
          tone={data.status === "cancelled" ? "danger" : "info"}
        />
        {data.lines && (
          <ul className="divide-y divide-border text-sm">
            {data.lines.map((line, index) => (
              <li key={index} className="flex justify-between gap-2 py-1.5">
                <span>{line.description}</span>
                <span className="tabular-nums text-muted-foreground">
                  {Number(line.delivered)}/{Number(line.qty)}
                </span>
              </li>
            ))}
          </ul>
        )}
        {data.total && (
          <p className="text-sm">
            Total <strong>{formatPen(data.total)}</strong>
            {Number(data.prepaid) > 0 && ` · adelanto ${formatPen(data.prepaid ?? 0)}`}
          </p>
        )}
        {data.kind === "shipment" ? <Timeline shipment={data} /> : null}
        {data.shipments?.map((shipment) => (
          <Timeline key={shipment.number} shipment={shipment} />
        ))}
      </div>
    </AuthShell>
  );
}

export const Route = createFileRoute("/seguimiento/$token")({
  component: TrackingPage,
});
