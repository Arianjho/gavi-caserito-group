import { createFileRoute } from "@tanstack/react-router";
import { useState } from "react";

import { ConfirmDialog } from "@/components/common/confirm-dialog";
import { EmptyState } from "@/components/common/empty-state";
import { ErrorState } from "@/components/common/error-state";
import { FormDialog } from "@/components/common/form-dialog";
import { StatusChip } from "@/components/common/status-chip";
import { DashboardLayout } from "@/components/dashboard/dashboard-layout";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { useGuardedMutation } from "@/hooks/use-guarded-mutation";
import { ApiError } from "@/lib/api-error";

/** Resultado simulado del servidor en esta demostración. */
type Scenario = "success" | "validation" | "system" | "slow" | "never";

const scenarios: { value: Scenario; label: string }[] = [
  { value: "success", label: "Éxito (1 s)" },
  { value: "validation", label: "Error corregible (RUC)" },
  { value: "system", label: "Falla del sistema" },
  { value: "slow", label: "Lento (18 s y éxito)" },
  { value: "never", label: "Sin respuesta (corta a los 30 s)" },
];

/**
 * Simula una petición al servidor con el comportamiento elegido.
 *
 * @param scenario Escenario a reproducir.
 * @param signal Señal de cancelación enviada por el contrato de interacción.
 */
function fakeServer(scenario: Scenario, signal: AbortSignal): Promise<{ id: string }> {
  return new Promise((resolve, reject) => {
    const wait = scenario === "slow" ? 18_000 : scenario === "never" ? 120_000 : 1_000;
    const timer = window.setTimeout(() => {
      if (scenario === "validation") {
        reject(
          new ApiError(422, {
            code: "validation_failed",
            fields: { tax_id: ["El RUC debe tener 11 dígitos y pasar la validación."] },
          }),
        );
      } else if (scenario === "system") {
        reject(new ApiError(500, { code: "system_error", request_id: "S-7F3A" }));
      } else {
        resolve({ id: crypto.randomUUID() });
      }
    }, wait);
    signal.addEventListener("abort", () => {
      window.clearTimeout(timer);
      reject(new DOMException("Aborted", "AbortError"));
    });
  });
}

/**
 * Página de desarrollo que demuestra el contrato de interacción:
 * bloqueo de formulario, tiempos máximos, avisos y estados vacío/error/carga.
 */
function InteractionDemo() {
  const [scenario, setScenario] = useState<Scenario>("success");
  const [createOpen, setCreateOpen] = useState(false);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [taxId, setTaxId] = useState("20601234567");
  const [name, setName] = useState("Tienda Mi Mundo");

  const create = useGuardedMutation({
    mutationFn: (_input: { taxId: string; name: string }, { signal }) =>
      fakeServer(scenario, signal),
    successMessage: "Cliente registrado",
    onSuccess: () => setCreateOpen(false),
  });

  const remove = useGuardedMutation({
    mutationFn: (_input: void, { signal }) => fakeServer(scenario, signal),
    successMessage: "Cliente eliminado",
    onSuccess: () => setConfirmOpen(false),
  });

  const taxIdError = create.fieldErrors["tax_id"]?.[0];

  return (
    <DashboardLayout
      title="Contrato de interacción"
      description="Bloqueo de contexto, avisos y estados. Solo visible en desarrollo."
    >
      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Simulación</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="flex flex-wrap gap-2">
              {scenarios.map((item) => (
                <Button
                  key={item.value}
                  size="sm"
                  variant={scenario === item.value ? "default" : "outline"}
                  onClick={() => setScenario(item.value)}
                >
                  {item.label}
                </Button>
              ))}
            </div>
            <div className="flex flex-wrap gap-2">
              <Button
                onClick={() => {
                  create.reset();
                  setCreateOpen(true);
                }}
              >
                Nuevo cliente
              </Button>
              <Button variant="destructive" onClick={() => setConfirmOpen(true)}>
                Eliminar cliente
              </Button>
            </div>
            <p className="text-sm text-muted-foreground">
              Mientras corre la petición, los campos y botones quedan bloqueados y el diálogo no se
              puede cerrar. Con "Sin respuesta", a los 30 s se corta y se informa que no se pudo
              confirmar.
            </p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="text-base">Estados</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="flex flex-wrap gap-2">
              <StatusChip label="Borrador" />
              <StatusChip label="Confirmado" tone="info" />
              <StatusChip label="Entregado" tone="success" />
              <StatusChip label="Pendiente" tone="warning" />
              <StatusChip label="Rechazado" tone="danger" />
            </div>
            <div className="space-y-2">
              <Skeleton className="h-4 w-2/3" />
              <Skeleton className="h-4 w-1/2" />
              <Skeleton className="h-4 w-3/4" />
            </div>
            <EmptyState
              title="Aún no hay clientes"
              description="Crea el primero o impórtalos desde Excel."
            />
            <ErrorState onRetry={() => undefined} />
          </CardContent>
        </Card>
      </div>

      <FormDialog
        open={createOpen}
        onOpenChange={setCreateOpen}
        title="Nuevo cliente"
        description="Datos básicos para vender y cobrar."
        submitLabel="Registrar cliente"
        processingLabel="Registrando…"
        processing={create.isProcessing}
        slow={create.isSlow}
        onSubmit={() => void create.run({ taxId, name })}
      >
        <div className="grid gap-2">
          <Label htmlFor="tax-id">RUC</Label>
          <Input
            id="tax-id"
            value={taxId}
            onChange={(event) => setTaxId(event.target.value)}
            aria-invalid={taxIdError !== undefined}
          />
          {taxIdError && <p className="text-xs text-destructive">{taxIdError}</p>}
        </div>
        <div className="grid gap-2">
          <Label htmlFor="name">Nombre o razón social</Label>
          <Input id="name" value={name} onChange={(event) => setName(event.target.value)} />
        </div>
      </FormDialog>

      <ConfirmDialog
        open={confirmOpen}
        onOpenChange={setConfirmOpen}
        title="Eliminar cliente"
        description={`Se eliminará "${name}". Esta acción no se puede deshacer.`}
        processing={remove.isProcessing}
        onConfirm={() => void remove.run()}
      />
    </DashboardLayout>
  );
}

export const Route = createFileRoute("/_app/dev/interaccion")({
  component: InteractionDemo,
});
