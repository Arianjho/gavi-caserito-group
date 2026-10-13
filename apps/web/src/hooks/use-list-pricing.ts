import { useEffect, type Dispatch, type SetStateAction } from "react";

import { api } from "@/lib/api";

/** Línea que admite reprecio por lista. */
interface PricedLine {
  variantId: string;
  qty: number;
  price: number;
  /** Precio editado a mano: no se vuelve a calcular. */
  manual?: boolean;
}

/**
 * Reprecia las líneas con la lista de precios del cliente (escala por
 * cantidad) cada vez que cambia el cliente, las variantes o las cantidades.
 * Las líneas con precio editado a mano se respetan.
 *
 * @param partyId Cliente elegido, o `null` si no hay.
 * @param lines Líneas actuales.
 * @param setLines Actualizador de las líneas.
 */
export function useListPricing<T extends PricedLine>(
  partyId: string | null,
  lines: T[],
  setLines: Dispatch<SetStateAction<T[]>>,
): void {
  const signature = lines.map((line) => `${line.variantId}:${line.qty}`).join("|");

  useEffect(() => {
    if (partyId === null || signature === "") return;
    const controller = new AbortController();
    const body = {
      party_id: partyId,
      lines: signature.split("|").map((entry) => {
        const [variantId = "", qty = "1"] = entry.split(":");
        return { variant_id: variantId, qty: Number(qty) };
      }),
    };
    api<{ data: { variant_id: string; unit_price: string | null }[] }>("/prices/resolve", {
      method: "POST",
      body,
      signal: controller.signal,
    })
      .then((response) => {
        const prices = new Map(
          response.data
            .filter((row) => row.unit_price !== null)
            .map((row) => [row.variant_id, Number(row.unit_price)] as const),
        );
        setLines((current) =>
          current.map((line) => {
            const price = prices.get(line.variantId);
            return line.manual || price === undefined || price === line.price
              ? line
              : { ...line, price };
          }),
        );
      })
      .catch(() => undefined);
    return () => controller.abort();
  }, [partyId, signature, setLines]);
}
