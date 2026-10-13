import { createFileRoute } from "@tanstack/react-router";
import { useQuery } from "@tanstack/react-query";
import { Banknote, Boxes, ClipboardList, ShoppingCart, Truck } from "lucide-react";

import { DashboardLayout } from "@/components/dashboard/dashboard-layout";
import { EmptyState } from "@/components/common/empty-state";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Progress } from "@/components/ui/progress";
import { api } from "@/lib/api";
import { formatPen } from "@/lib/money";

/** Indicador del panel de inicio. */
interface Kpi {
  label: string;
  value: string;
  hint: string;
  icon: typeof ShoppingCart;
}

/** Datos del panel según los permisos del usuario. */
interface DashboardData {
  sales_today?: { count: number; total: string };
  receivables?: { balance_total: string; overdue_count: number };
  payables?: { balance_total: string; overdue_count: number };
  active_orders?: number;
  open_shipments?: number;
  negative_stock?: number;
  top_products?: { name: string; qty: string; total: string }[];
}

/** Pasos de arranque de un negocio nuevo. */
const firstSteps: { label: string; done: boolean }[] = [
  { label: "Datos de tu empresa", done: true },
  { label: "Sucursal y almacén", done: true },
  { label: "Cargar productos (Excel o manual)", done: false },
  { label: "Cargar clientes", done: false },
  { label: "Registrar tu primera venta", done: false },
  { label: "Activar facturación electrónica (opcional)", done: false },
];

/**
 * Panel de inicio: ventas, cobranzas, pedidos y primeros pasos.
 *
 * Las cifras vienen de `GET /dashboard` y solo incluyen lo que el usuario
 * tiene permiso de ver.
 */
function HomePage() {
  const dashboard = useQuery({
    queryKey: ["dashboard"],
    queryFn: async () => (await api<{ data: DashboardData }>("/dashboard")).data,
  });
  const data = dashboard.data;
  const kpis: Kpi[] = [];
  if (data?.sales_today) {
    kpis.push({
      label: "Ventas de hoy",
      value: formatPen(data.sales_today.total),
      hint: `${data.sales_today.count} documentos`,
      icon: ShoppingCart,
    });
  }
  if (data?.receivables) {
    kpis.push({
      label: "Por cobrar",
      value: formatPen(data.receivables.balance_total),
      hint:
        data.receivables.overdue_count > 0
          ? `${data.receivables.overdue_count} vencidos`
          : "Sin deudas vencidas",
      icon: Banknote,
    });
  }
  if (data?.active_orders !== undefined) {
    kpis.push({
      label: "Pedidos activos",
      value: String(data.active_orders),
      hint: "Confirmados, en despacho y listos",
      icon: ClipboardList,
    });
  }
  if (data?.open_shipments !== undefined) {
    kpis.push({
      label: "Envíos abiertos",
      value: String(data.open_shipments),
      hint: "Por despachar o en camino",
      icon: Truck,
    });
  }

  const attention: string[] = [];
  if (data?.receivables && data.receivables.overdue_count > 0) {
    attention.push(`${data.receivables.overdue_count} cuenta(s) por cobrar vencida(s)`);
  }
  if (data?.payables && data.payables.overdue_count > 0) {
    attention.push(`${data.payables.overdue_count} cuenta(s) por pagar vencida(s)`);
  }
  if (data?.negative_stock && data.negative_stock > 0) {
    attention.push(`${data.negative_stock} producto(s) con stock negativo`);
  }
  const done = firstSteps.filter((step) => step.done).length;
  const progress = Math.round((done / firstSteps.length) * 100);

  return (
    <DashboardLayout title="Inicio" description="Cuánto vendiste, qué te deben y qué atender hoy.">
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {kpis.map((kpi) => (
          <Card key={kpi.label}>
            <CardContent className="flex items-start justify-between gap-3 p-4">
              <div className="min-w-0">
                <p className="text-xs text-muted-foreground">{kpi.label}</p>
                <p className="mt-1 text-2xl font-bold tabular-nums">{kpi.value}</p>
                <p className="mt-1 truncate text-xs text-muted-foreground">{kpi.hint}</p>
              </div>
              <span className="grid size-9 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                <kpi.icon className="size-4" aria-hidden="true" />
              </span>
            </CardContent>
          </Card>
        ))}
      </div>

      <div className="mt-4 grid gap-4 lg:grid-cols-[2fr_1fr]">
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Primeros pasos</CardTitle>
          </CardHeader>
          <CardContent className="space-y-3">
            <div className="flex items-center gap-3">
              <Progress value={progress} className="h-2" />
              <span className="text-xs tabular-nums text-muted-foreground">
                {done} de {firstSteps.length}
              </span>
            </div>
            <ul className="divide-y divide-border">
              {firstSteps.map((step) => (
                <li key={step.label} className="flex items-center gap-3 py-2 text-sm">
                  <span
                    className={
                      step.done
                        ? "grid size-5 place-items-center rounded-full bg-success text-[11px] text-success-foreground"
                        : "grid size-5 place-items-center rounded-full border border-border text-[11px] text-muted-foreground"
                    }
                    aria-hidden="true"
                  >
                    {step.done ? "✓" : ""}
                  </span>
                  <span className={step.done ? "text-muted-foreground line-through" : ""}>
                    {step.label}
                  </span>
                </li>
              ))}
            </ul>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="text-base">Atención hoy</CardTitle>
          </CardHeader>
          <CardContent>
            {attention.length === 0 ? (
              <EmptyState
                icon={Boxes}
                title="Todo en orden"
                description="Aquí verás deudas vencidas y stock en negativo."
              />
            ) : (
              <ul className="space-y-2 text-sm">
                {attention.map((item) => (
                  <li key={item} className="rounded-md bg-warning/10 px-3 py-2">
                    {item}
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>
      </div>
    </DashboardLayout>
  );
}

export const Route = createFileRoute("/_app/")({
  component: HomePage,
});
