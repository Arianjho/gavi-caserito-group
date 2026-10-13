/**
 * Da formato de soles a un importe: `S/ 1,234.50`.
 *
 * @param value Importe numérico o texto decimal.
 * @returns Texto con símbolo, separador de miles y dos decimales.
 */
export function formatPen(value: number | string): string {
  const number = typeof value === "string" ? Number(value) : value;
  return `S/ ${number.toLocaleString("es-PE", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

/**
 * Redondea un importe a céntimos (mitad hacia arriba) sin acumular error de coma flotante.
 *
 * @param value Importe a redondear.
 */
export function roundMoney(value: number): number {
  return Math.round((value + Number.EPSILON) * 100) / 100;
}
