import { createFileRoute } from "@tanstack/react-router";
import { useQuery } from "@tanstack/react-query";
import { useState } from "react";

import { EmptyState } from "@/components/common/empty-state";
import { ErrorState } from "@/components/common/error-state";
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
import { api } from "@/lib/api";

/** Evento de la bitácora. */
interface AuditEvent {
  id: number;
  action: string;
  entity_type: string;
  entity_id: string;
  changes: Record<string, { old: unknown; new: unknown } | unknown> | null;
  actor_name: string | null;
  created_at: string;
}

interface AuditResponse {
  data: AuditEvent[];
  meta: { total: number; page: number; last_page: number };
}

const ACTIONS: Record<string, string> = {
  created: "Creó",
  updated: "Modificó",
  deleted: "Eliminó",
  restored: "Restauró",
};

/** Nombres amigables de las tablas más consultadas. */
const ENTITIES: Record<string, string> = {
  "cat.items": "Producto",
  "cat.item_variants": "Variante",
  "cat.parties": "Tercero",
  "cat.brands": "Marca",
  "cat.categories": "Categoría",
  "cat.price_lists": "Lista de precios",
  "core.users": "Usuario",
  "core.roles": "Perfil",
  "core.companies": "Empresa",
  "core.branches": "Sucursal",
  "core.warehouses": "Almacén",
};

/** Resume los campos modificados de un evento. */
function summarize(changes: AuditEvent["changes"]): string {
  if (!changes) return "—";
  const fields = Object.keys(changes);
  return fields.length > 4 ? `${fields.slice(0, 4).join(", ")}…` : fields.join(", ") || "—";
}

/**
 * Bitácora de auditoría: quién creó o modificó qué y cuándo. Solo lectura.
 */
function AuditPage() {
  const [entity, setEntity] = useState("");
  const [page, setPage] = useState(1);

  const query = useQuery({
    queryKey: ["audit-log", entity, page],
    queryFn: () =>
      api<AuditResponse>(`/audit-log?page=${page}&entity_type=${encodeURIComponent(entity)}`),
  });

  return (
    <DashboardLayout
      title="Auditoría"
      description="Registro de cambios hechos por tu equipo; no se puede editar."
    >
      <div className="mb-4 grid max-w-xs gap-1">
        <Label htmlFor="audit-entity">Filtrar por tipo de registro</Label>
        <Input
          id="audit-entity"
          placeholder="Por ejemplo: items, parties, users"
          value={entity}
          onChange={(event) => {
            setEntity(event.target.value);
            setPage(1);
          }}
        />
      </div>

      {query.isPending && <Skeleton className="h-24 w-full" />}
      {query.isError && <ErrorState onRetry={() => void query.refetch()} />}
      {query.data?.data.length === 0 && (
        <EmptyState title="Sin eventos" description="No hay cambios que coincidan con el filtro." />
      )}
      {query.data && query.data.data.length > 0 && (
        <>
          <Card className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Fecha</TableHead>
                  <TableHead>Usuario</TableHead>
                  <TableHead>Acción</TableHead>
                  <TableHead>Registro</TableHead>
                  <TableHead>Campos</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {query.data.data.map((event) => (
                  <TableRow key={event.id}>
                    <TableCell className="whitespace-nowrap">
                      {new Date(event.created_at).toLocaleString("es-PE")}
                    </TableCell>
                    <TableCell>{event.actor_name ?? "Sistema"}</TableCell>
                    <TableCell>{ACTIONS[event.action] ?? event.action}</TableCell>
                    <TableCell>{ENTITIES[event.entity_type] ?? event.entity_type}</TableCell>
                    <TableCell className="max-w-xs truncate text-muted-foreground">
                      {summarize(event.changes)}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </Card>
          <div className="mt-3 flex items-center justify-between text-sm text-muted-foreground">
            <span>
              Página {query.data.meta.page} de {query.data.meta.last_page} · {query.data.meta.total}{" "}
              eventos
            </span>
            <span className="space-x-2">
              <Button
                variant="outline"
                size="sm"
                disabled={page <= 1}
                onClick={() => setPage(page - 1)}
              >
                Anterior
              </Button>
              <Button
                variant="outline"
                size="sm"
                disabled={page >= query.data.meta.last_page}
                onClick={() => setPage(page + 1)}
              >
                Siguiente
              </Button>
            </span>
          </div>
        </>
      )}
    </DashboardLayout>
  );
}

export const Route = createFileRoute("/_app/configuracion/auditoria")({
  component: AuditPage,
});
