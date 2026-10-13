/** Variante tal como la devuelve la API de productos. */
interface VariantLike {
  name?: string | null;
  status?: string;
}

/**
 * Convierte productos con varias variantes en una entrada vendible por
 * variante activa ("Polo M / Rojo"), conservando el resto de los campos.
 * Un producto con una sola variante se devuelve sin cambios.
 *
 * @param products Productos con su lista de variantes.
 * @returns Lista plana lista para buscar, agregar al carrito o elegir.
 */
export function expandVariants<V extends VariantLike, P extends { name: string; variants: V[] }>(
  products: P[],
): P[] {
  return products.flatMap((product) => {
    const active = product.variants.filter((variant) => variant.status !== "inactive");
    if (active.length <= 1) {
      return [{ ...product, variants: active }];
    }
    return active.map((variant) => ({
      ...product,
      name: variant.name ? `${product.name} ${variant.name}` : product.name,
      variants: [variant],
    }));
  });
}
