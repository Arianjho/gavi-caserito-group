/** Columna de una exportación CSV. */
export interface CsvColumn<T> {
  header: string;
  value: (row: T) => string | number | null | undefined;
}

/** Escapa un valor para CSV (comillas dobles y separadores). */
function escapeCell(value: string | number | null | undefined): string {
  const text = value === null || value === undefined ? "" : String(value);
  return /[",\n;]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
}

/**
 * Descarga las filas como CSV con BOM para que Excel respete las tildes.
 *
 * @param filename Nombre del archivo (con `.csv`).
 * @param columns Columnas en orden.
 * @param rows Filas a exportar.
 */
export function downloadCsv<T>(filename: string, columns: CsvColumn<T>[], rows: T[]): void {
  const lines = [
    columns.map((column) => escapeCell(column.header)).join(","),
    ...rows.map((row) => columns.map((column) => escapeCell(column.value(row))).join(",")),
  ];
  const bom = String.fromCharCode(0xfeff);
  const blob = new Blob([bom + lines.join("\r\n")], { type: "text/csv;charset=utf-8" });
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  link.click();
  URL.revokeObjectURL(url);
}
