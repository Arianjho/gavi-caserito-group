import { createFileRoute } from "@tanstack/react-router";
import { useQuery } from "@tanstack/react-query";
import { useState } from "react";

import { EmptyState } from "@/components/common/empty-state";
import { ErrorState } from "@/components/common/error-state";
import { DashboardLayout } from "@/components/dashboard/dashboard-layout";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
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
import { api } from "@/lib/api";
import { downloadCsv } from "@/lib/csv";
import { formatPen } from "@/lib/money";

/** Reporte de ventas del periodo. */
interface SalesReport {
  from: string;
  to: string;
  total: string;
  documents: number;
  daily: { day: string; documents: number; total: string }[];
  by_method: { method: string; total: string }[];
  revenue_net: string;
  cost: string;
  gross_margin: string;
  expenses: string;
  estimated_result: string;
}

/** Fila del reporte por producto. */
interface ProductRow {
  id: string;
  name: string;
  qty: string;
  total: string;
  revenue: string;
  cost: string;
  margin: string;
}

const METHODS: Record<string, string> = {
  cash: "Efectivo",
  card: "Tarjeta",
  yape: "Yape",
  plin: "Plin",
  transfer: "Transferencia",
  credit: "Crédito",
  other: "Otro",
};

/** Primer día del mes en curso en formato `YYYY-MM-DD`. */
function monthStart(): string {
  const now = new Date();
  return new Date(now.getFullYear(), now.getMonth(), 1, 12).toISOString().slice(0, 10);
}

/** Tarjeta con una cifra destacada. */
function Metric({ label, value, hint }: { label: string; value: string; hint?: string }) {
  return (
    <Card>
      <CardContent className="p-4">
        <p className="text-sm text-muted-foreground">{label}</p>
        <p className="text-2xl font-bold tabular-nums">{value}</p>
        {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
      </CardContent>
    </Card>
  );
}

/**
 * Reportes de gestión del periodo: ventas, medios de cobro, margen,
 * gastos y resultado estimado; y ventas por producto.
 */
function ReportsPage() {
  const [from, setFrom] = useState(monthStart);
  const [to, setTo] = useState(() => new Date().toISOString().slice(0, 10));
  const query = `from=${from}&to=${to}`;

  const sales = useQuery({
    queryKey: ["reports", "sales", from, to],
    queryFn: async () => (await api<{ data: SalesReport }>(`/reports/sales?${query}`)).data,
  });
  const products = useQuery({
    queryKey: ["reports", "products", from, to],
    queryFn: async () => (await api<{ data: ProductRow[] }>(`/reports/products?${query}`)).data,
  });

  const report = sales.data;

  return (
    <DashboardLayout title="Reportes" description="Cómo va tu negocio en el periodo elegido.">
      <div className="mb-4 flex flex-wrap items-end gap-3">
        <div className="grid gap-1">
          <Label htmlFor="r-from">Desde</Label>
          <Input
            id="r-from"
            type="date"
            value={from}
            onChange={(event) => setFrom(event.target.value)}
          />
        </div>
        <div className="grid gap-1">
          <Label htmlFor="r-to">Hasta</Label>
          <Input id="r-to" type="date" value={to} onChange={(event) => setTo(event.target.value)} />
        </div>
      </div>

      {sales.isPending && <Skeleton className="h-32 w-full" />}
      {sales.isError && <ErrorState onRetry={() => void sales.refetch()} />}
      {report && (
        <div className="space-y-6">
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <Metric
              label="Ventas"
              value={formatPen(report.total)}
              hint={`${report.documents} documentos`}
            />
            <Metric label="Margen bruto" value={formatPen(report.gross_margin)} />
            <Metric label="Gastos" value={formatPen(report.expenses)} />
            <Metric
              label="Resultado estimado"
              value={formatPen(report.estimated_result)}
              hint="Margen bruto menos gastos"
            />
          </div>

          <div className="grid gap-4 lg:grid-cols-2">
            <Card className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Día</TableHead>
                    <TableHead className="text-right">Documentos</TableHead>
                    <TableHead className="text-right">Total</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {report.daily.map((row) => (
                    <TableRow key={row.day}>
                      <TableCell>{row.day}</TableCell>
                      <TableCell className="text-right tabular-nums">{row.documents}</TableCell>
                      <TableCell className="text-right tabular-nums">
                        {formatPen(row.total)}
                      </TableCell>
                    </TableRow>
                  ))}
                  {report.daily.length === 0 && (
                    <TableRow>
                      <TableCell colSpan={3} className="text-center text-muted-foreground">
                        Sin ventas en el periodo
                      </TableCell>
                    </TableRow>
                  )}
                </TableBody>
              </Table>
            </Card>
            <Card className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Medio de cobro</TableHead>
                    <TableHead className="text-right">Total</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {report.by_method.map((row) => (
                    <TableRow key={row.method}>
                      <TableCell>{METHODS[row.method] ?? row.method}</TableCell>
                      <TableCell className="text-right tabular-nums">
                        {formatPen(row.total)}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </Card>
          </div>

          <section className="space-y-2">
            <div className="flex items-center justify-between">
              <h2 className="text-base font-semibold">Ventas por producto</h2>
              <Button
                variant="outline"
                size="sm"
                disabled={!products.data || products.data.length === 0}
                onClick={() =>
                  downloadCsv(
                    `ventas-por-producto-${from}_${to}.csv`,
                    [
                      { header: "Producto", value: (r: ProductRow) => r.name },
                      { header: "Cantidad", value: (r) => r.qty },
                      { header: "Ingreso neto", value: (r) => r.revenue },
                      { header: "Costo", value: (r) => r.cost },
                      { header: "Margen", value: (r) => r.margin },
                    ],
                    products.data ?? [],
                  )
                }
              >
                Exportar CSV
              </Button>
            </div>
            {products.data?.length === 0 && (
              <EmptyState title="Sin ventas" description="No hay ventas en este periodo." />
            )}
            {products.data && products.data.length > 0 && (
              <Card className="overflow-x-auto">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Producto</TableHead>
                      <TableHead className="text-right">Cantidad</TableHead>
                      <TableHead className="text-right">Ingreso neto</TableHead>
                      <TableHead className="text-right">Costo</TableHead>
                      <TableHead className="text-right">Margen</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {products.data.map((row) => (
                      <TableRow key={row.id}>
                        <TableCell className="font-medium">{row.name}</TableCell>
                        <TableCell className="text-right tabular-nums">{Number(row.qty)}</TableCell>
                        <TableCell className="text-right tabular-nums">
                          {formatPen(row.revenue)}
                        </TableCell>
                        <TableCell className="text-right tabular-nums">
                          {formatPen(row.cost)}
                        </TableCell>
                        <TableCell className="text-right tabular-nums">
                          {formatPen(row.margin)}
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </Card>
            )}
          </section>
        </div>
      )}
    </DashboardLayout>
  );
}

export const Route = createFileRoute("/_app/reportes")({
  component: ReportsPage,
});
