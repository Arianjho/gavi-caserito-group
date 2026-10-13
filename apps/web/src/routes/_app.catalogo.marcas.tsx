import { createFileRoute } from "@tanstack/react-router";

import { SimpleMasterPage } from "@/features/organization/simple-master-page";

/** Pantalla de marcas de productos. */
function BrandsPage() {
  return (
    <SimpleMasterPage
      path="brands"
      title="Marcas"
      description="Para filtrar y reportar tus productos por marca."
      singular="marca"
      gender="f"
    />
  );
}

export const Route = createFileRoute("/_app/catalogo/marcas")({
  component: BrandsPage,
});
