import { createFileRoute } from "@tanstack/react-router";
import { useQuery } from "@tanstack/react-query";
import { Package, Plus, Search } from "lucide-react";
import { useEffect, useState } from "react";

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
import { VariantsDialog } from "@/features/catalog/variants-dialog";
import { ResourceActions } from "@/features/organization/resource-actions";
import {
  useInvalidateResource,
  useResourceList,
  type LifecycleStatus,
} from "@/features/organization/resources";
import { useGuardedMutation } from "@/hooks/use-guarded-mutation";
import { api } from "@/lib/api";

/** Variante tal como la entrega la API. */
interface Variant {
  id: string;
  sku: string | null;
  name: string | null;
  status: string;
  is_default: boolean;
  cost_reference: string | null;
  sale_price: string | null;
  barcodes: string[];
}

/** Producto o servicio con sus variantes. */
interface Product {
  id: string;
  status: LifecycleStatus;
  kind: "good" | "service";
  name: string;
  category_id: string | null;
  brand_id: string | null;
  variants: Variant[];
}

/** Maestro simple usado en los selectores. */
interface Option {
  id: string;
  name: string;
}

/** Datos del formulario de producto. */
interface ProductForm {
  name: string;
  kind: "good" | "service";
  sku: string;
  barcode: string;
  cost_reference: string;
  sale_price: string;
  category_id: string;
  brand_id: string;
}

const emptyForm: ProductForm = {
  name: "",
  kind: "good",
  sku: "",
  barcode: "",
  cost_reference: "",
  sale_price: "",
  category_id: "",
  brand_id: "",
};

/**
 * Pantalla de productos y servicios.
 *
 * Búsqueda por nombre, código, SKU o código de barras; alta y edición con la
 * variante predeterminada. Las variantes múltiples se gestionan más adelante.
 */
function ProductsPage() {
  const [term, setTerm] = useState("");
  const [debounced, setDebounced] = useState("");
  useEffect(() => {
    const timer = window.setTimeout(() => setDebounced(term.trim()), 300);
    return () => window.clearTimeout(timer);
  }, [term]);

  const list = useQuery({
    queryKey: ["resource", "items", debounced],
    queryFn: async () =>
      (
        await api<{ data: Product[] }>(
          `/items${debounced ? `?q=${encodeURIComponent(debounced)}` : ""}`,
        )
      ).data,
  });
  const categories = useResourceList<Option>("categories");
  const brands = useResourceList<Option>("brands");
  const invalidate = useInvalidateResource("items");

  const [open, setOpen] = useState(false);
  const [variantsFor, setVariantsFor] = useState<string | null>(null);
  const variantsProduct = list.data?.find((product) => product.id === variantsFor);
  const [editing, setEditing] = useState<Product | null>(null);
  const [form, setForm] = useState<ProductForm>(emptyForm);

  const save = useGuardedMutation({
    mutationFn: (input: ProductForm, { signal, idempotencyKey }) => {
      const body = {
        ...input,
        category_id: input.category_id || null,
        brand_id: input.brand_id || null,
        sku: input.sku || null,
        barcode: input.barcode || null,
        cost_reference: input.cost_reference === "" ? null : Number(input.cost_reference),
        sale_price: input.sale_price === "" ? null : Number(input.sale_price),
      };
      return editing
        ? api(`/items/${editing.id}`, { method: "PUT", body, signal })
        : api("/items", { method: "POST", body, signal, idempotencyKey });
    },
    successMessage: () => (editing ? "Producto actualizado" : "Producto registrado"),
    onSuccess: () => {
      setOpen(false);
      void invalidate();
    },
  });

  const openDialog = (product: Product | null) => {
    save.reset();
    setEditing(product);
    const variant = product?.variants.find((v) => v.is_default);
    setForm(
      product
        ? {
            name: product.name,
            kind: product.kind,
            sku: variant?.sku ?? "",
            barcode: variant?.barcodes[0] ?? "",
            cost_reference: variant?.cost_reference ?? "",
            sale_price: variant?.sale_price ?? "",
            category_id: product.category_id ?? "",
            brand_id: product.brand_id ?? "",
          }
        : emptyForm,
    );
    setOpen(true);
  };

  const err = (name: string) => save.fieldErrors[name]?.[0];
  const text = (name: keyof ProductForm, label: string, hint?: string) => (
    <div className="grid gap-2">
      <Label htmlFor={`p-${name}`}>{label}</Label>
      <Input
        id={`p-${name}`}
        value={form[name]}
        onChange={(event) => setForm({ ...form, [name]: event.target.value })}
        aria-invalid={err(name) !== undefined}
        placeholder={hint}
      />
      {err(name) && <p className="text-xs text-destructive">{err(name)}</p>}
    </div>
  );
  const select = (
    name: "category_id" | "brand_id",
    label: string,
    options: Option[] | undefined,
  ) => (
    <div className="grid gap-2">
      <Label htmlFor={`p-${name}`}>{label}</Label>
      <select
        id={`p-${name}`}
        value={form[name]}
        onChange={(event) => setForm({ ...form, [name]: event.target.value })}
        className="h-9 rounded-md border border-input bg-background px-3 text-sm"
      >
        <option value="">Sin {label.toLowerCase()}</option>
        {(options ?? []).map((option) => (
          <option key={option.id} value={option.id}>
            {option.name}
          </option>
        ))}
      </select>
    </div>
  );

  return (
    <DashboardLayout
      title="Productos"
      description="Lo que vendes: bienes y servicios, con su código de barras."
      actions={
        <Button onClick={() => openDialog(null)}>
          <Plus /> Nuevo producto
        </Button>
      }
    >
      <div className="relative mb-4 max-w-md">
        <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
        <Input
          value={term}
          onChange={(event) => setTerm(event.target.value)}
          placeholder="Buscar por nombre, SKU o código de barras"
          className="pl-9"
          aria-label="Buscar productos"
        />
      </div>

      {list.isPending && (
        <div className="space-y-2">
          <Skeleton className="h-10 w-full" />
          <Skeleton className="h-10 w-full" />
          <Skeleton className="h-10 w-full" />
        </div>
      )}
      {list.isError && <ErrorState onRetry={() => void list.refetch()} />}
      {list.data?.length === 0 && (
        <EmptyState
          icon={Package}
          title={debounced ? "Sin resultados" : "Aún no tienes productos"}
          description={
            debounced
              ? "Prueba con otro nombre o escanea el código de barras."
              : "Registra tu primer producto o impórtalos desde Excel."
          }
          action={
            debounced ? undefined : <Button onClick={() => openDialog(null)}>Nuevo producto</Button>
          }
        />
      )}
      {list.data && list.data.length > 0 && (
        <Card className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Producto</TableHead>
                <TableHead>SKU</TableHead>
                <TableHead>Código de barras</TableHead>
                <TableHead className="text-right">Precio</TableHead>
                <TableHead>Estado</TableHead>
                <TableHead className="w-40" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {list.data.map((product) => {
                const variant = product.variants.find((v) => v.is_default);
                return (
                  <TableRow key={product.id}>
                    <TableCell>
                      <div className="font-medium">{product.name}</div>
                      <div className="text-xs text-muted-foreground">
                        {product.kind === "service" ? "Servicio" : "Producto"}
                      </div>
                    </TableCell>
                    <TableCell className="tabular-nums">{variant?.sku ?? "—"}</TableCell>
                    <TableCell className="tabular-nums">{variant?.barcodes[0] ?? "—"}</TableCell>
                    <TableCell className="text-right tabular-nums">
                      {variant?.sale_price ? `S/ ${Number(variant.sale_price).toFixed(2)}` : "—"}
                    </TableCell>
                    <TableCell>
                      <StatusChip
                        label={product.status === "active" ? "Activo" : "Inactivo"}
                        tone={product.status === "active" ? "success" : "neutral"}
                      />
                    </TableCell>
                    <TableCell className="space-x-1 whitespace-nowrap">
                      {product.kind === "good" && (
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => setVariantsFor(product.id)}
                        >
                          Variantes
                          {product.variants.length > 1 ? ` (${product.variants.length})` : ""}
                        </Button>
                      )}
                      <ResourceActions
                        path="items"
                        id={product.id}
                        name={product.name}
                        status={product.status}
                        onEdit={() => openDialog(product)}
                      />
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
        </Card>
      )}

      {variantsProduct && (
        <VariantsDialog
          open
          onOpenChange={(next) => !next && setVariantsFor(null)}
          productId={variantsProduct.id}
          productName={variantsProduct.name}
          variants={variantsProduct.variants}
        />
      )}

      <FormDialog
        open={open}
        onOpenChange={setOpen}
        title={editing ? "Editar producto" : "Nuevo producto"}
        submitLabel={editing ? "Guardar cambios" : "Registrar producto"}
        processingLabel={editing ? "Guardando…" : "Registrando…"}
        processing={save.isProcessing}
        slow={save.isSlow}
        onSubmit={() => void save.run(form)}
      >
        {text("name", "Nombre")}
        <div className="grid grid-cols-2 gap-3">
          {text("sku", "SKU (opcional)")}
          {text("barcode", "Código de barras", "Escanea aquí")}
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div className="grid gap-2">
            <Label htmlFor="p-kind">Tipo</Label>
            <select
              id="p-kind"
              value={form.kind}
              onChange={(event) =>
                setForm({ ...form, kind: event.target.value as "good" | "service" })
              }
              className="h-9 rounded-md border border-input bg-background px-3 text-sm"
            >
              <option value="good">Producto (con stock)</option>
              <option value="service">Servicio</option>
            </select>
          </div>
          {text("cost_reference", "Costo de referencia")}
        </div>
        {text("sale_price", "Precio de venta (con IGV)")}
        <div className="grid grid-cols-2 gap-3">
          {select("category_id", "Categoría", categories.data)}
          {select("brand_id", "Marca", brands.data)}
        </div>
      </FormDialog>
    </DashboardLayout>
  );
}

export const Route = createFileRoute("/_app/catalogo/productos")({
  component: ProductsPage,
});
