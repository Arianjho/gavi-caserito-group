import { useQuery } from "@tanstack/react-query";
import { useState } from "react";

import { EmptyState } from "@/components/common/empty-state";
import { ErrorState } from "@/components/common/error-state";
import { DashboardLayout } from "@/components/dashboard/dashboard-layout";
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
import { api } from "@/lib/api";
import { formatPen } from "@/lib/money";

/** Movimiento de cobro o pago. */
interface LedgerRow {
  id: string;
  method: string;
  amount: string;
  reference: string | null;
  paid_at: string;
  document_number: number;
  party_name: string | null;
}

const METHODS: Record<string, string> = {
  cash: "Efectivo",
  card: "Tarjeta",
  yape: "Yape",
  plin: "Plin",
  transfer: "Transferencia",
  other: "Otro",
};

/** Propiedades de {@link LedgerPage}. */
export interface LedgerPageProps {
  title: string;
  description: string;
  endpoint: "/collections" | "/payments";
  partyLabel: string;
}

/**
 * Listado de cobros o pagos con filtro por fechas y medio, y total del rango.
 */
export function LedgerPage({ title, description, endpoint, partyLabel }: LedgerPageProps) {
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [method, setMethod] = useState("");

  const list = useQuery({
    queryKey: ["ledger", endpoint, from, to, method],
    queryFn: async () => {
      const params = new URLSearchParams();
      if (from) params.set("from", from);
      if (to) params.set("to", to);
      if (method) params.set("method", method);
      return await api<{ data: LedgerRow[]; meta: { total: string } }>(
        `${endpoint}?${params.toString()}`,
      );
    },
  });

  return (
    <DashboardLayout title={title} description={description}>
      <div className="mb-4 flex flex-wrap items-end gap-3">
        <div className="grid gap-1">
          <Label htmlFor="l-from">Desde</Label>
          <Input id="l-from" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
        </div>
        <div className="grid gap-1">
          <Label htmlFor="l-to">Hasta</Label>
          <Input id="l-to" type="date" value={to} onChange={(e) => setTo(e.target.value)} />
        </div>
        <div className="grid gap-1">
          <Label htmlFor="l-method">Medio</Label>
          <select
            id="l-method"
            value={method}
            onChange={(e) => setMethod(e.target.value)}
            className="h-9 rounded-md border border-input bg-background px-3 text-sm"
          >
            <option value="">Todos</option>
            {Object.entries(METHODS).map(([key, label]) => (
              <option key={key} value={key}>
                {label}
              </option>
            ))}
          </select>
        </div>
        {list.data && (
          <p className="ml-auto text-sm">
            Total: <strong className="tabular-nums">{formatPen(list.data.meta.total)}</strong>
          </p>
        )}
      </div>

      {list.isPending && <Skeleton className="h-24 w-full" />}
      {list.isError && <ErrorState onRetry={() => void list.refetch()} />}
      {list.data?.data.length === 0 && (
        <EmptyState title="Sin movimientos" description="No hay registros para estos filtros." />
      )}
      {list.data && list.data.data.length > 0 && (
        <Card className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Fecha</TableHead>
                <TableHead>{partyLabel}</TableHead>
                <TableHead>Documento</TableHead>
                <TableHead>Medio</TableHead>
                <TableHead>Referencia</TableHead>
                <TableHead className="text-right">Importe</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {list.data.data.map((row) => (
                <TableRow key={row.id}>
                  <TableCell className="whitespace-nowrap">
                    {new Date(row.paid_at).toLocaleString("es-PE")}
                  </TableCell>
                  <TableCell>{row.party_name ?? "Cliente varios"}</TableCell>
                  <TableCell className="tabular-nums">#{row.document_number}</TableCell>
                  <TableCell>{METHODS[row.method] ?? row.method}</TableCell>
                  <TableCell className="text-muted-foreground">{row.reference ?? "—"}</TableCell>
                  <TableCell className="text-right tabular-nums">{formatPen(row.amount)}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Card>
      )}
    </DashboardLayout>
  );
}
