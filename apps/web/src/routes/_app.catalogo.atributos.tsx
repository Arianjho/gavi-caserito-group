import { createFileRoute } from "@tanstack/react-router";

import { SimpleMasterPage } from "@/features/organization/simple-master-page";

/** Catálogo de atributos reutilizables para variantes. */
function AttributesPage() {
  return (
    <SimpleMasterPage
      path="attributes"
      title="Atributos"
      description="Talla, color… se sugieren al crear variantes de un producto."
      singular="atributo"
      gender="m"
      extraField={{
        key: "suggested_values",
        label: "Valores sugeridos",
        placeholder: "S, M, L, XL",
      }}
    />
  );
}

export const Route = createFileRoute("/_app/catalogo/atributos")({
  component: AttributesPage,
});
