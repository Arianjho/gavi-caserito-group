import { createFileRoute } from "@tanstack/react-router";
import { useState } from "react";

import { Button } from "@/components/ui/button";
import { PriceListItemsDialog } from "@/features/catalog/price-list-items-dialog";
import { SimpleMasterPage } from "@/features/organization/simple-master-page";

/** Listas de precios (mayorista, distribuidor...) con escalas por cantidad. */
function PriceListsPage() {
  const [editing, setEditing] = useState<{ id: string; name: string } | null>(null);

  return (
    <>
      <SimpleMasterPage
        path="price-lists"
        title="Listas de precios"
        description="Precios distintos para clientes mayoristas, distribuidores o escalas por cantidad."
        singular="lista de precios"
        gender="f"
        rowActions={(record) => (
          <Button
            variant="ghost"
            size="sm"
            onClick={() => setEditing({ id: record.id, name: record.name })}
          >
            Precios
          </Button>
        )}
      />
      {editing && (
        <PriceListItemsDialog
          listId={editing.id}
          listName={editing.name}
          onClose={() => setEditing(null)}
        />
      )}
    </>
  );
}

export const Route = createFileRoute("/_app/catalogo/listas-precios")({
  component: PriceListsPage,
});
