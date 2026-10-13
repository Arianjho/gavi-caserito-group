import { createFileRoute } from "@tanstack/react-router";

import { SimpleMasterPage } from "@/features/organization/simple-master-page";

/** Pantalla de categorías de productos. */
function CategoriesPage() {
  return (
    <SimpleMasterPage
      path="categories"
      title="Categorías"
      description="Agrupa tus productos para encontrarlos y reportarlos más fácil."
      singular="categoría"
      gender="f"
    />
  );
}

export const Route = createFileRoute("/_app/catalogo/categorias")({
  component: CategoriesPage,
});
