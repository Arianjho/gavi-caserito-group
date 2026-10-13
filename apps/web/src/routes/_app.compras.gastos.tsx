import { createFileRoute } from "@tanstack/react-router";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus, Receipt } from "lucide-react";
import { useEffect, useState } from "react";

import { EmptyState } from "@/components/common/empty-state";
import { ErrorState } from "@/components/common/error-state";
import { FormDialog } from "@/components/common/form-dialog";
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
import { useResourceList, type Company } from "@/features/organization/resources";
import { useGuardedMutation } from "@/hooks/use-guarded-mutation";
import { api } from "@/lib/api";
import { formatPen } from "@/lib/money";

/** Gasto registrado. */
interface Expense {
  id: string;
  category: string;
  description: string;
  method: string;
  amount: string;
  paid_at: string;
}

interface ExpensesResponse {
  data: Expense[];
  by_category: { category: string; total: string }[];
  total: string;
}

const METHODS: Record<string, string> = {
  cash: "Efectivo",
  card: "Tarjeta",
  yape: "Yape",
  plin: "Plin",
  transfer: "Transferencia",
  other: "Otro",
};

/** Rubros frecuentes sugeridos al escribir. */
const CATEGORIES = ["Alquiler", "Servicios", "Sueldos", "Transporte", "Publicidad", "Impuestos"];

const emptyForm = { category: "", description: "", method: "cash", amount: "" };

/**
 * Gastos operativos: registro con medio de pago, total por rubro y anulación.
 * Los gastos en efectivo descuentan del turno de caja abierto.
 */
function ExpensesPage() {
  const queryClient = useQueryClient();
  const list = useQuery({
    queryKey: ["expenses"],
    queryFn: () => api<ExpensesResponse>("/expenses"),
  });
  const companies = useResourceList<Company>("companies");
  const company = (companies.data ?? []).find((c) => c.status === "active");
  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ["expenses"] });
    void queryClient.invalidateQueries({ queryKey: ["cash"] });
  };

  const [open, setOpen] = useState(false);
  const [form, setForm] = useState(emptyForm);
  useEffect(() => {
    if (!open) setForm(emptyForm);
  }, [open]);

  const save = useGuardedMutation({
    mutationFn: (_input: void, { signal, idempotencyKey }) =>
      api("/expenses", {
        method: "POST",
        signal,
        idempotencyKey,
        body: { ...form, company_id: company?.id, amount: Number(form.amount) },
      }),
    successMessage: "Gasto registrado",
    onSuccess: () => {
      setOpen(false);
      refresh();
    },
  });

  const voidExpense = useGuardedMutation({
    mutationFn: (id: string, { signal }) =>
      api(`/expenses/${id}/void`, {
        method: "POST",
        signal,
        body: { reason: "Anulado desde Gastos" },
      }),
    successMessage: "Gasto anulado",
    onSuccess: refresh,
  });

  const error = (name: string) => save.fieldErrors[name]?.[0];

  return (
    <DashboardLayout
      title="Gastos"
      description="Alquiler, servicios, sueldos y otros pagos del negocio."
      actions={
        <Button
          disabled={!company}
          onClick={() => {
            save.reset();
            setOpen(true);
          }}
        >
          <Plus /> Registrar gasto
        </Button>
      }
    >
      {list.isPending && <Skeleton className="h-24 w-full" />}
      {list.isError && <ErrorState onRetry={() => void list.refetch()} />}
      {list.data && list.data.data.length === 0 && (
        <EmptyState
          icon={Receipt}
          title="Sin gastos registrados"
          description="Registra aquí lo que pagas para operar: así sabes cuánto te queda."
        />
      )}
      {list.data && list.data.data.length > 0 && (
        <div className="space-y-4">
          <div className="grid gap-3 sm:grid-cols-3">
            <Card>
              <CardContent className="p-4">
                <p className="text-sm text-muted-foreground">Total de gastos</p>
                <p className="text-2xl font-bold tabular-nums">{formatPen(list.data.total)}</p>
              </CardContent>
            </Card>
            {list.data.by_category.slice(0, 2).map((row) => (
              <Card key={row.category}>
                <CardContent className="p-4">
                  <p className="text-sm text-muted-foreground">{row.category}</p>
                  <p className="text-2xl font-bold tabular-nums">{formatPen(row.total)}</p>
                </CardContent>
              </Card>
            ))}
          </div>
          <Card className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Fecha</TableHead>
                  <TableHead>Rubro</TableHead>
                  <TableHead>Detalle</TableHead>
                  <TableHead>Medio</TableHead>
                  <TableHead className="text-right">Importe</TableHead>
                  <TableHead className="w-24" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {list.data.data.map((expense) => (
                  <TableRow key={expense.id}>
                    <TableCell>{new Date(expense.paid_at).toLocaleDateString("es-PE")}</TableCell>
                    <TableCell className="font-medium">{expense.category}</TableCell>
                    <TableCell>{expense.description}</TableCell>
                    <TableCell>{METHODS[expense.method] ?? expense.method}</TableCell>
                    <TableCell className="text-right tabular-nums">
                      {formatPen(expense.amount)}
                    </TableCell>
                    <TableCell className="text-right">
                      <Button
                        variant="ghost"
                        size="sm"
                        disabled={voidExpense.isProcessing}
                        onClick={() => void voidExpense.run(expense.id)}
                      >
                        Anular
                      </Button>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </Card>
        </div>
      )}

      <FormDialog
        open={open}
        onOpenChange={setOpen}
        title="Registrar gasto"
        submitLabel="Registrar"
        processingLabel="Registrando…"
        processing={save.isProcessing}
        slow={save.isSlow}
        onSubmit={() => void save.run()}
      >
        <div className="grid gap-2">
          <Label htmlFor="e-category">Rubro</Label>
          <Input
            id="e-category"
            list="expense-categories"
            value={form.category}
            onChange={(event) => setForm({ ...form, category: event.target.value })}
            aria-invalid={error("category") !== undefined}
          />
          <datalist id="expense-categories">
            {CATEGORIES.map((name) => (
              <option key={name} value={name} />
            ))}
          </datalist>
          {error("category") && <p className="text-xs text-destructive">{error("category")}</p>}
        </div>
        <div className="grid gap-2">
          <Label htmlFor="e-description">Detalle</Label>
          <Input
            id="e-description"
            value={form.description}
            onChange={(event) => setForm({ ...form, description: event.target.value })}
            aria-invalid={error("description") !== undefined}
          />
          {error("description") && (
            <p className="text-xs text-destructive">{error("description")}</p>
          )}
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div className="grid gap-2">
            <Label htmlFor="e-amount">Importe</Label>
            <Input
              id="e-amount"
              inputMode="decimal"
              value={form.amount}
              onChange={(event) => setForm({ ...form, amount: event.target.value })}
              aria-invalid={error("amount") !== undefined}
            />
            {error("amount") && <p className="text-xs text-destructive">{error("amount")}</p>}
          </div>
          <div className="grid gap-2">
            <Label htmlFor="e-method">Medio de pago</Label>
            <select
              id="e-method"
              value={form.method}
              onChange={(event) => setForm({ ...form, method: event.target.value })}
              className="h-9 rounded-md border border-input bg-background px-3 text-sm"
            >
              {Object.entries(METHODS).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </select>
          </div>
        </div>
      </FormDialog>
    </DashboardLayout>
  );
}

export const Route = createFileRoute("/_app/compras/gastos")({
  component: ExpensesPage,
});
