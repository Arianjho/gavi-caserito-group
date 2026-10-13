import { createFileRoute } from "@tanstack/react-router";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { useEffect, useState } from "react";

import { ActionButton } from "@/components/common/action-button";
import { DashboardLayout } from "@/components/dashboard/dashboard-layout";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { useGuardedMutation } from "@/hooks/use-guarded-mutation";
import { api } from "@/lib/api";

/** Marca del negocio. */
interface Branding {
  brand_color: string | null;
  brand_tagline: string | null;
}

/**
 * Marca del negocio: color y lema que ve el cliente final en el enlace de
 * seguimiento de sus pedidos y envíos.
 */
function BrandingPage() {
  const queryClient = useQueryClient();
  const current = useQuery({
    queryKey: ["branding"],
    queryFn: async () => (await api<{ data: Branding }>("/branding")).data,
  });
  const [color, setColor] = useState("#2563eb");
  const [useColor, setUseColor] = useState(false);
  const [tagline, setTagline] = useState("");

  useEffect(() => {
    if (!current.data) return;
    setUseColor(current.data.brand_color !== null);
    setColor(current.data.brand_color ?? "#2563eb");
    setTagline(current.data.brand_tagline ?? "");
  }, [current.data]);

  const save = useGuardedMutation({
    mutationFn: (_input: void, { signal }) =>
      api("/branding", {
        method: "PUT",
        signal,
        body: { brand_color: useColor ? color : null, brand_tagline: tagline || null },
      }),
    successMessage: "Marca guardada",
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ["branding"] }),
  });

  return (
    <DashboardLayout
      title="Marca"
      description="Cómo ve tu cliente el enlace de seguimiento de sus pedidos."
    >
      <Card className="max-w-md space-y-4 p-4">
        <div className="grid gap-2">
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={useColor}
              onChange={(event) => setUseColor(event.target.checked)}
            />
            Usar un color de marca
          </label>
          {useColor && (
            <input
              type="color"
              aria-label="Color de marca"
              value={color}
              onChange={(event) => setColor(event.target.value)}
              className="h-10 w-20 cursor-pointer rounded border border-input"
            />
          )}
        </div>
        <div className="grid gap-2">
          <Label htmlFor="tagline">Lema (opcional)</Label>
          <Input
            id="tagline"
            maxLength={120}
            value={tagline}
            onChange={(event) => setTagline(event.target.value)}
            placeholder="Juguetes que sacan sonrisas"
          />
          {save.fieldErrors["brand_tagline"]?.[0] && (
            <p className="text-xs text-destructive">{save.fieldErrors["brand_tagline"][0]}</p>
          )}
        </div>
        <ActionButton
          processing={save.isProcessing}
          processingLabel="Guardando…"
          onClick={() => void save.run()}
        >
          Guardar marca
        </ActionButton>
      </Card>
    </DashboardLayout>
  );
}

export const Route = createFileRoute("/_app/configuracion/marca")({
  component: BrandingPage,
});
