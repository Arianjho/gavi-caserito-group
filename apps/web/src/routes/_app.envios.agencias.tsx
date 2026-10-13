import { createFileRoute } from "@tanstack/react-router";

import { SimpleMasterPage } from "@/features/organization/simple-master-page";

/** Pantalla de agencias de transporte. */
function CarriersPage() {
  return (
    <SimpleMasterPage
      path="carriers"
      title="Agencias"
      description="Shalom, Olva, Marvisur, tu reparto propio… las que usas para enviar."
      singular="agencia"
      gender="f"
    />
  );
}

export const Route = createFileRoute("/_app/envios/agencias")({
  component: CarriersPage,
});
