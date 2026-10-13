import { createFileRoute } from "@tanstack/react-router";
import { useQueryClient } from "@tanstack/react-query";
import { Download, FileSpreadsheet } from "lucide-react";
import { readSheet } from "read-excel-file/browser";
import { useEffect, useState } from "react";

import { ActionButton } from "@/components/common/action-button";
import { StatusChip } from "@/components/common/status-chip";
import { DashboardLayout } from "@/components/dashboard/dashboard-layout";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { useResourceList, type Warehouse } from "@/features/organization/resources";
import { useGuardedMutation } from "@/hooks/use-guarded-mutation";
import { api } from "@/lib/api";

/** Campos que entiende la API y los encabezados aceptados para cada uno. */
const COLUMN_ALIASES: Record<string, string[]> = {
  name: ["nombre", "producto", "descripcion", "descripción", "name"],
  sku: ["sku", "codigo", "código", "codigo interno", "código interno"],
  barcode: ["codigo de barras", "código de barras", "barras", "ean", "barcode"],
  category: ["categoria", "categoría", "category"],
  brand: ["marca", "brand"],
  cost: ["costo", "costo unitario", "cost"],
  price: ["precio", "precio de venta", "precio venta", "price"],
  stock: ["stock", "cantidad", "existencias", "stock inicial"],
};

const TEMPLATE_HEADERS = [
  "Nombre",
  "SKU",
  "Código de barras",
  "Categoría",
  "Marca",
  "Costo",
  "Precio",
  "Stock",
];

/** Resultado por fila devuelto por la API. */
interface RowResult {
  row: number;
  name: string;
  status: "valid" | "created" | "error";
  errors: string[];
}

interface ImportResult {
  created: number;
  failed: number;
  rows: RowResult[];
}

/** Convierte la hoja (primera fila = encabezados) en objetos para la API. */
function sheetToRows(data: unknown[][]): Record<string, unknown>[] {
  const [header, ...body] = data;
  if (!header) return [];
  const fieldByColumn = header.map((cell) => {
    const text = String(cell ?? "")
      .trim()
      .toLowerCase();
    return Object.keys(COLUMN_ALIASES).find((field) => COLUMN_ALIASES[field]?.includes(text));
  });
  return body
    .filter((row) => row.some((cell) => cell !== null && cell !== ""))
    .map((row) => {
      const out: Record<string, unknown> = {};
      fieldByColumn.forEach((field, index) => {
        const value = row[index];
        if (field && value !== null && value !== undefined && value !== "") {
          out[field] = typeof value === "number" ? value : String(value).trim();
        }
      });
      return out;
    });
}

/** Descarga una plantilla CSV (se abre directo en Excel). */
function downloadTemplate() {
  const sample = ["Polo básico", "POLO-001", "7750000000011", "Ropa", "Gavi", "10", "29.90", "5"];
  const bom = String.fromCharCode(0xfeff);
  const csv = `${bom}${TEMPLATE_HEADERS.join(",")}
${sample.join(",")}
`;
  const url = URL.createObjectURL(new Blob([csv], { type: "text/csv;charset=utf-8" }));
  const link = document.createElement("a");
  link.href = url;
  link.download = "plantilla-productos.csv";
  link.click();
  URL.revokeObjectURL(url);
}

/**
 * Importación de productos desde Excel: se lee el archivo en el navegador,
 * se valida en el servidor y recién entonces se importa. Las filas con error
 * se reportan sin detener las demás.
 */
function ImportPage() {
  const queryClient = useQueryClient();
  const warehouses = useResourceList<Warehouse>("warehouses");
  const [warehouseId, setWarehouseId] = useState("");
  useEffect(() => {
    const first = warehouses.data?.find((w) => w.status === "active");
    if (!warehouseId && first) setWarehouseId(first.id);
  }, [warehouses.data, warehouseId]);

  const [fileName, setFileName] = useState("");
  const [rows, setRows] = useState<Record<string, unknown>[]>([]);
  const [readError, setReadError] = useState<string | null>(null);
  const [result, setResult] = useState<ImportResult | null>(null);
  const [imported, setImported] = useState(false);

  const onFile = async (file: File | undefined) => {
    setResult(null);
    setImported(false);
    setReadError(null);
    if (!file) return;
    setFileName(file.name);
    try {
      const data = (await readSheet(file)) as unknown[][];
      const parsed = sheetToRows(data);
      setRows(parsed);
      if (parsed.length === 0) setReadError("No se encontraron filas con datos.");
    } catch {
      setRows([]);
      setReadError("No se pudo leer el archivo. Usa un Excel .xlsx.");
    }
  };

  const run = useGuardedMutation({
    mutationFn: (dryRun: boolean, { signal }) =>
      api<{ data: ImportResult }>("/import/items", {
        method: "POST",
        signal,
        body: { rows, warehouse_id: warehouseId || null, dry_run: dryRun },
      }),
    successMessage: (response) =>
      response.data.created > 0 ? "Importación terminada" : "Archivo validado",
    onSuccess: (response) => {
      setResult(response.data);
      if (response.data.created > 0) {
        setImported(true);
        void queryClient.invalidateQueries({ queryKey: ["items"] });
        void queryClient.invalidateQueries({ queryKey: ["inventory"] });
      }
    },
  });

  const validated = result !== null && !imported;
  const canImport = validated && result.failed < rows.length;

  return (
    <DashboardLayout
      title="Importar productos"
      description="Carga tu catálogo desde un archivo Excel."
      actions={
        <Button variant="outline" onClick={downloadTemplate}>
          <Download /> Descargar plantilla
        </Button>
      }
    >
      <Card className="max-w-3xl">
        <CardContent className="space-y-4 p-4">
          <div className="grid gap-2">
            <Label htmlFor="import-file">Archivo Excel (.xlsx)</Label>
            <Input
              id="import-file"
              type="file"
              accept=".xlsx"
              onChange={(event) => void onFile(event.target.files?.[0])}
            />
            <p className="text-xs text-muted-foreground">
              Columnas: Nombre (obligatoria), SKU, Código de barras, Categoría, Marca, Costo, Precio
              y Stock. Categorías y marcas nuevas se crean solas. La plantilla se abre en Excel:
              guárdala como .xlsx.
            </p>
            {readError && <p className="text-sm text-destructive">{readError}</p>}
          </div>

          <div className="grid gap-2">
            <Label htmlFor="import-warehouse">Almacén para el stock inicial</Label>
            <select
              id="import-warehouse"
              value={warehouseId}
              onChange={(event) => setWarehouseId(event.target.value)}
              className="h-9 rounded-md border border-input bg-background px-3 text-sm"
            >
              {(warehouses.data ?? [])
                .filter((w) => w.status === "active")
                .map((w) => (
                  <option key={w.id} value={w.id}>
                    {w.name}
                  </option>
                ))}
            </select>
          </div>

          {rows.length > 0 && (
            <div className="flex flex-wrap items-center gap-3">
              <span className="flex items-center gap-2 text-sm">
                <FileSpreadsheet className="size-4" /> {fileName}: {rows.length} filas
              </span>
              <ActionButton
                variant="outline"
                processing={run.isProcessing}
                processingLabel="Validando…"
                onClick={() => void run.run(true)}
              >
                Validar
              </ActionButton>
              {canImport && (
                <ActionButton
                  processing={run.isProcessing}
                  processingLabel="Importando…"
                  onClick={() => void run.run(false)}
                >
                  Importar {rows.length - result.failed} productos
                </ActionButton>
              )}
            </div>
          )}

          {result && (
            <p className="text-sm">
              {imported
                ? `Se importaron ${result.created} productos; ${result.failed} filas con error.`
                : `${rows.length - result.failed} filas válidas y ${result.failed} con error.`}
            </p>
          )}
        </CardContent>
      </Card>

      {result && result.rows.some((r) => r.status === "error") && (
        <Card className="mt-4 max-w-3xl overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="w-16">Fila</TableHead>
                <TableHead>Producto</TableHead>
                <TableHead>Problema</TableHead>
                <TableHead className="w-24">Estado</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {result.rows
                .filter((r) => r.status === "error")
                .map((r) => (
                  <TableRow key={r.row}>
                    <TableCell className="tabular-nums">{r.row + 1}</TableCell>
                    <TableCell>{r.name || "—"}</TableCell>
                    <TableCell>{r.errors.join(" ")}</TableCell>
                    <TableCell>
                      <StatusChip label="Error" tone="danger" />
                    </TableCell>
                  </TableRow>
                ))}
            </TableBody>
          </Table>
        </Card>
      )}
    </DashboardLayout>
  );
}

export const Route = createFileRoute("/_app/configuracion/importar")({
  component: ImportPage,
});
