import { createFileRoute } from "@tanstack/react-router";

import { LedgerPage } from "@/features/payments/ledger-page";

/** Cobros recibidos de clientes. */
function CollectionsPage() {
  return (
    <LedgerPage
      title="Cobros"
      description="Dinero recibido de tus clientes."
      endpoint="/collections"
      partyLabel="Cliente"
    />
  );
}

export const Route = createFileRoute("/_app/cobranzas/cobros")({
  component: CollectionsPage,
});
