import { createFileRoute } from "@tanstack/react-router";
import { useQuery } from "@tanstack/react-query";

import { ErrorState } from "@/components/common/error-state";
import { DashboardLayout } from "@/components/dashboard/dashboard-layout";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { api } from "@/lib/api";

/** Uso frente al tope de un recurso. */
interface Limit {
  max: number | null;
  used: number;
  /** Consumo del cupo; solo lo informa el cupo mensual de comprobantes. */
  level?: "ok" | "warning" | "reached";
}

/** Plan de la cuenta con su uso. */
interface PlanSummary {
  plan_code: string;
  plan_status: string;
  trial_ends_at: string | null;
  limits: { users: Limit; companies: Limit; documents_month: Limit };
}

const LABELS: Record<keyof PlanSummary["limits"], string> = {
  users: "Usuarios activos",
  companies: "Empresas activas",
  documents_month: "Comprobantes electrónicos este mes",
};

/** Barra de uso; sin tope solo muestra el número. */
function UsageRow({ label, limit }: { label: string; limit: Limit }) {
  const percent = limit.max ? Math.min(100, (limit.used / limit.max) * 100) : 0;
  return (
    <div className="space-y-1">
      <div className="flex justify-between text-sm">
        <span>{label}</span>
        <span className="tabular-nums text-muted-foreground">
          {limit.used} / {limit.max ?? "sin tope"}
        </span>
      </div>
      {limit.level === "reached" && (
        <p className="text-xs text-destructive">
          Llegaste al cupo: las ventas siguen, pero los comprobantes nuevos no se emiten hasta
          ampliar el plan o iniciar el mes siguiente.
        </p>
      )}
      {limit.level === "warning" && (
        <p className="text-xs text-muted-foreground">
          Estás cerca del cupo del mes; considera ampliar tu plan.
        </p>
      )}
      {limit.max !== null && (
        <div className="h-2 overflow-hidden rounded-full bg-muted">
          <div
            className={`h-full ${limit.level === "reached" || percent >= 90 ? "bg-destructive" : "bg-primary"}`}
            style={{ width: `${percent}%` }}
          />
        </div>
      )}
    </div>
  );
}

/** Pantalla de plan y uso de la cuenta. */
function PlanPage() {
  const plan = useQuery({
    queryKey: ["plan"],
    queryFn: async () => (await api<{ data: PlanSummary }>("/plan")).data,
  });

  return (
    <DashboardLayout title="Mi plan" description="Tu plan actual y cuánto llevas usado.">
      {plan.isPending && <Skeleton className="h-32 w-full" />}
      {plan.isError && <ErrorState onRetry={() => void plan.refetch()} />}
      {plan.data && (
        <Card className="max-w-lg space-y-4 p-4">
          <p className="text-sm">
            Plan <strong>{plan.data.plan_code}</strong> · estado {plan.data.plan_status}
            {plan.data.trial_ends_at &&
              ` · prueba hasta ${new Date(plan.data.trial_ends_at).toLocaleDateString("es-PE")}`}
          </p>
          {(Object.keys(LABELS) as (keyof typeof LABELS)[]).map((key) => (
            <UsageRow key={key} label={LABELS[key]} limit={plan.data.limits[key]} />
          ))}
        </Card>
      )}
    </DashboardLayout>
  );
}

export const Route = createFileRoute("/_app/configuracion/plan")({
  component: PlanPage,
});
