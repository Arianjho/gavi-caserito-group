import { createFileRoute } from "@tanstack/react-router";
import { Plus, Store, Warehouse as WarehouseIcon } from "lucide-react";
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
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { ResourceActions } from "@/features/organization/resource-actions";
import {
  useInvalidateResource,
  useResourceList,
  type Branch,
  type Company,
  type Warehouse,
} from "@/features/organization/resources";
import { useGuardedMutation } from "@/hooks/use-guarded-mutation";
import { api } from "@/lib/api";

const KIND_LABEL: Record<Warehouse["kind"], string> = {
  sales: "Venta",
  storage: "Depósito",
  transit: "Tránsito",
};

/**
 * Pantalla de sucursales y almacenes.
 *
 * Una sucursal pertenece a una empresa y un almacén a una sucursal.
 */
function BranchesPage() {
  const [tab, setTab] = useState<"branches" | "warehouses">("branches");
  const companies = useResourceList<Company>("companies");
  const branches = useResourceList<Branch>("branches");
  const warehouses = useResourceList<Warehouse>("warehouses");
  const invalidateBranches = useInvalidateResource("branches");
  const invalidateWarehouses = useInvalidateResource("warehouses");

  const [open, setOpen] = useState(false);
  const [name, setName] = useState("");
  const [code, setCode] = useState("0000");
  const [parentId, setParentId] = useState("");

  const isBranchTab = tab === "branches";
  const activeParents = isBranchTab
    ? (companies.data ?? []).filter((c) => c.status === "active")
    : (branches.data ?? []).filter((b) => b.status === "active");

  const save = useGuardedMutation({
    mutationFn: (_input: void, { signal, idempotencyKey }) =>
      isBranchTab
        ? api("/branches", {
            method: "POST",
            signal,
            idempotencyKey,
            body: { company_id: parentId, code, name },
          })
        : api("/warehouses", {
            method: "POST",
            signal,
            idempotencyKey,
            body: { branch_id: parentId, name },
          }),
    successMessage: () => (isBranchTab ? "Sucursal registrada" : "Almacén registrado"),
    onSuccess: () => {
      setOpen(false);
      void (isBranchTab ? invalidateBranches() : invalidateWarehouses());
    },
  });

  const openCreate = () => {
    save.reset();
    setName("");
    setCode("0000");
    setParentId(activeParents[0]?.id ?? "");
    setOpen(true);
  };

  const branchName = (id: string) => branches.data?.find((b) => b.id === id)?.name ?? "—";
  const companyName = (id: string) => companies.data?.find((c) => c.id === id)?.legal_name ?? "—";
  const nameError = save.fieldErrors["name"]?.[0];
  const codeError = save.fieldErrors["code"]?.[0];
  const parentError = save.fieldErrors["company_id"]?.[0] ?? save.fieldErrors["branch_id"]?.[0];

  const query = isBranchTab ? branches : warehouses;

  return (
    <DashboardLayout
      title="Sucursales y almacenes"
      description="Dónde vendes y dónde guardas tu stock."
      actions={
        <Button onClick={openCreate}>
          <Plus /> {isBranchTab ? "Nueva sucursal" : "Nuevo almacén"}
        </Button>
      }
    >
      <Tabs value={tab} onValueChange={(value) => setTab(value as typeof tab)}>
        <TabsList>
          <TabsTrigger value="branches">Sucursales</TabsTrigger>
          <TabsTrigger value="warehouses">Almacenes</TabsTrigger>
        </TabsList>
      </Tabs>

      <div className="mt-4">
        {query.isPending && <Skeleton className="h-24 w-full" />}
        {query.isError && <ErrorState onRetry={() => void query.refetch()} />}

        {isBranchTab && branches.data?.length === 0 && (
          <EmptyState
            icon={Store}
            title="Aún no tienes sucursales"
            description="Crea tu primer local; necesitas una empresa registrada."
            action={<Button onClick={openCreate}>Registrar sucursal</Button>}
          />
        )}
        {!isBranchTab && warehouses.data?.length === 0 && (
          <EmptyState
            icon={WarehouseIcon}
            title="Aún no tienes almacenes"
            description="Cada sucursal necesita al menos un almacén para controlar stock."
            action={<Button onClick={openCreate}>Registrar almacén</Button>}
          />
        )}

        {isBranchTab && branches.data && branches.data.length > 0 && (
          <Card className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Código</TableHead>
                  <TableHead>Nombre</TableHead>
                  <TableHead>Empresa</TableHead>
                  <TableHead>Estado</TableHead>
                  <TableHead className="w-10" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {branches.data.map((branch) => (
                  <TableRow key={branch.id}>
                    <TableCell className="tabular-nums">{branch.code}</TableCell>
                    <TableCell className="font-medium">
                      {branch.name}
                      {branch.is_main && (
                        <span className="ml-2 text-xs text-muted-foreground">principal</span>
                      )}
                    </TableCell>
                    <TableCell>{companyName(branch.company_id)}</TableCell>
                    <TableCell>
                      <StatusChip
                        label={branch.status === "active" ? "Activa" : "Inactiva"}
                        tone={branch.status === "active" ? "success" : "neutral"}
                      />
                    </TableCell>
                    <TableCell>
                      <ResourceActions
                        path="branches"
                        id={branch.id}
                        name={branch.name}
                        status={branch.status}
                      />
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </Card>
        )}

        {!isBranchTab && warehouses.data && warehouses.data.length > 0 && (
          <Card className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Nombre</TableHead>
                  <TableHead>Sucursal</TableHead>
                  <TableHead>Tipo</TableHead>
                  <TableHead>Estado</TableHead>
                  <TableHead className="w-10" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {warehouses.data.map((warehouse) => (
                  <TableRow key={warehouse.id}>
                    <TableCell className="font-medium">{warehouse.name}</TableCell>
                    <TableCell>{branchName(warehouse.branch_id)}</TableCell>
                    <TableCell>{KIND_LABEL[warehouse.kind]}</TableCell>
                    <TableCell>
                      <StatusChip
                        label={warehouse.status === "active" ? "Activo" : "Inactivo"}
                        tone={warehouse.status === "active" ? "success" : "neutral"}
                      />
                    </TableCell>
                    <TableCell>
                      <ResourceActions
                        path="warehouses"
                        id={warehouse.id}
                        name={warehouse.name}
                        status={warehouse.status}
                      />
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </Card>
        )}
      </div>

      <FormDialog
        open={open}
        onOpenChange={setOpen}
        title={isBranchTab ? "Nueva sucursal" : "Nuevo almacén"}
        submitLabel={isBranchTab ? "Registrar sucursal" : "Registrar almacén"}
        processingLabel="Registrando…"
        processing={save.isProcessing}
        slow={save.isSlow}
        onSubmit={() => void save.run()}
      >
        <div className="grid gap-2">
          <Label htmlFor="parent">{isBranchTab ? "Empresa" : "Sucursal"}</Label>
          <select
            id="parent"
            value={parentId}
            onChange={(event) => setParentId(event.target.value)}
            className="h-9 rounded-md border border-input bg-background px-3 text-sm"
          >
            {activeParents.map((parent) => (
              <option key={parent.id} value={parent.id}>
                {"legal_name" in parent ? parent.legal_name : parent.name}
              </option>
            ))}
          </select>
          {parentError && <p className="text-xs text-destructive">{parentError}</p>}
        </div>
        {isBranchTab && (
          <div className="grid gap-2">
            <Label htmlFor="code">Código de local (4 caracteres)</Label>
            <Input
              id="code"
              value={code}
              maxLength={4}
              onChange={(event) => setCode(event.target.value)}
              aria-invalid={codeError !== undefined}
            />
            {codeError && <p className="text-xs text-destructive">{codeError}</p>}
          </div>
        )}
        <div className="grid gap-2">
          <Label htmlFor="name">Nombre</Label>
          <Input
            id="name"
            value={name}
            onChange={(event) => setName(event.target.value)}
            aria-invalid={nameError !== undefined}
          />
          {nameError && <p className="text-xs text-destructive">{nameError}</p>}
        </div>
      </FormDialog>
    </DashboardLayout>
  );
}

export const Route = createFileRoute("/_app/configuracion/sucursales")({
  component: BranchesPage,
});
