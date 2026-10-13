import { createFileRoute } from "@tanstack/react-router";

import { LedgerPage } from "@/features/payments/ledger-page";

/** Pagos hechos a proveedores. */
function PaymentsPage() {
  return (
    <LedgerPage
      title="Pagos"
      description="Dinero pagado a tus proveedores."
      endpoint="/payments"
      partyLabel="Proveedor"
    />
  );
}

export const Route = createFileRoute("/_app/pagos/pagos")({
  component: PaymentsPage,
});
