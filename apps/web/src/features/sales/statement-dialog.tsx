import { useQuery } from "@tanstack/react-query";
import { Printer } from "lucide-react";

import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
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

/** Movimiento del estado de cuenta. */
interface StatementEvent {
  at: string;
  type: "sale" | "payment";
  label: string;
  charge: string;
  payment: string;
  balance: string;
  due_date: string | null;
}

/** Propiedades de {@link StatementDialog}. */
export interface StatementDialogProps {
  customerId: string | null;
  onClose: () => void;
}

/**
 * Estado de cuenta de un cliente: ventas, cobros y saldo corrido, con opción
 * de imprimir para entregarlo al cliente.
 */
export function StatementDialog({ customerId, onClose }: StatementDialogProps) {
  const statement = useQuery({
    queryKey: ["statement", customerId],
    enabled: customerId !== null,
    queryFn: async () =>
      (
        await api<{
          data: {
            party: { name: string; doc_number: string | null };
            events: StatementEvent[];
            balance: string;
          };
        }>(`/customers/${customerId}/statement`)
      ).data,
  });

  return (
    <Dialog open={customerId !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle>Estado de cuenta</DialogTitle>
          <DialogDescription>
            {statement.data
              ? `${statement.data.party.name}${statement.data.party.doc_number ? ` · ${statement.data.party.doc_number}` : ""}`
              : "Cargando…"}
          </DialogDescription>
        </DialogHeader>
        {statement.isPending && <Skeleton className="h-24 w-full" />}
        {statement.data && (
          <div className="space-y-3">
            <div className="max-h-80 overflow-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Fecha</TableHead>
                    <TableHead>Detalle</TableHead>
                    <TableHead className="text-right">Cargo</TableHead>
                    <TableHead className="text-right">Abono</TableHead>
                    <TableHead className="text-right">Saldo</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {statement.data.events.map((event, index) => (
                    <TableRow key={`${event.at}-${index}`}>
                      <TableCell className="whitespace-nowrap">
                        {new Date(event.at).toLocaleDateString("es-PE")}
                      </TableCell>
                      <TableCell>{event.label}</TableCell>
                      <TableCell className="text-right tabular-nums">
                        {Number(event.charge) > 0 ? formatPen(event.charge) : ""}
                      </TableCell>
                      <TableCell className="text-right tabular-nums">
                        {Number(event.payment) > 0 ? formatPen(event.payment) : ""}
                      </TableCell>
                      <TableCell className="text-right tabular-nums">
                        {formatPen(event.balance)}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
            <div className="flex items-center justify-between">
              <p className="text-sm">
                Saldo actual:{" "}
                <strong className="tabular-nums">{formatPen(statement.data.balance)}</strong>
              </p>
              <Button variant="outline" size="sm" onClick={() => window.print()}>
                <Printer /> Imprimir
              </Button>
            </div>
          </div>
        )}
      </DialogContent>
    </Dialog>
  );
}
