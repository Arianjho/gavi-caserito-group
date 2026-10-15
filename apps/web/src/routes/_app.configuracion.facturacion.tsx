import { createFileRoute } from "@tanstack/react-router";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { useEffect, useState } from "react";

import { ActionButton } from "@/components/common/action-button";
import { DashboardLayout } from "@/components/dashboard/dashboard-layout";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { useResourceList, type Company } from "@/features/organization/resources";
import { useGuardedMutation } from "@/hooks/use-guarded-mutation";
import { api } from "@/lib/api";

/** Configuración de eFact devuelta por la API (sin secretos). */
interface Settings {
  base_url: string;
  environment: "beta" | "production";
  boleta_series: string;
  invoice_series: string;
  credit_invoice_series: string;
  credit_boleta_series: string;
  dispatch_series: string;
  enabled: boolean;
  has_credentials: boolean;
  has_webhook_secret: boolean;
}

/**
 * Conexión de la empresa con Gavi eFact: URL, credenciales y series.
 * Los secretos se guardan cifrados y no se vuelven a mostrar.
 */
function FiscalSettingsPage() {
  const queryClient = useQueryClient();
  const companies = useResourceList<Company>("companies");
  const company = (companies.data ?? []).find((c) => c.status === "active");

  const settings = useQuery({
    queryKey: ["fiscal", "settings", company?.id],
    enabled: company !== undefined,
    queryFn: async () =>
      (await api<{ data: Settings | null }>(`/fiscal/settings/${company?.id}`)).data,
  });

  const [form, setForm] = useState({
    base_url: "https://efact.gavi-cloud.com",
    environment: "beta" as "beta" | "production",
    api_key: "",
    api_secret: "",
    webhook_secret: "",
    boleta_series: "B001",
    invoice_series: "F001",
    credit_invoice_series: "FC01",
    credit_boleta_series: "BC01",
    dispatch_series: "T001",
  });

  useEffect(() => {
    if (settings.data) {
      setForm((current) => ({
        ...current,
        base_url: settings.data?.base_url ?? current.base_url,
        environment: settings.data?.environment ?? current.environment,
        boleta_series: settings.data?.boleta_series ?? current.boleta_series,
        invoice_series: settings.data?.invoice_series ?? current.invoice_series,
        credit_invoice_series:
          settings.data?.credit_invoice_series ?? current.credit_invoice_series,
        credit_boleta_series: settings.data?.credit_boleta_series ?? current.credit_boleta_series,
        dispatch_series: settings.data?.dispatch_series ?? current.dispatch_series,
      }));
    }
  }, [settings.data]);

  const save = useGuardedMutation({
    mutationFn: (_input: void, { signal }) =>
      api(`/fiscal/settings/${company?.id}`, {
        method: "PUT",
        signal,
        body: {
          ...form,
          api_key: form.api_key || null,
          api_secret: form.api_secret || null,
          webhook_secret: form.webhook_secret || null,
        },
      }),
    successMessage: "Configuración guardada",
    onSuccess: () => {
      setForm((current) => ({ ...current, api_key: "", api_secret: "", webhook_secret: "" }));
      void queryClient.invalidateQueries({ queryKey: ["fiscal", "settings"] });
    },
  });

  const field = (
    name: keyof typeof form,
    label: string,
    options?: { secret?: boolean; hint?: string },
  ) => (
    <div className="grid gap-2">
      <Label htmlFor={`f-${name}`}>{label}</Label>
      <Input
        id={`f-${name}`}
        type={options?.secret ? "password" : "text"}
        autoComplete="off"
        value={form[name]}
        placeholder={options?.hint}
        onChange={(event) => setForm({ ...form, [name]: event.target.value })}
        aria-invalid={save.fieldErrors[name] !== undefined}
      />
      {save.fieldErrors[name]?.[0] && (
        <p className="text-xs text-destructive">{save.fieldErrors[name][0]}</p>
      )}
    </div>
  );

  return (
    <DashboardLayout
      title="Facturación electrónica"
      description={
        company
          ? `Conexión con Gavi eFact para ${company.trade_name ?? company.legal_name}.`
          : "Primero registra una empresa."
      }
    >
      {company && (
        <Card className="max-w-xl">
          <CardContent className="space-y-4 p-4">
            {field("base_url", "URL de eFact")}
            <div className="grid gap-2">
              <Label htmlFor="f-environment">Entorno</Label>
              <select
                id="f-environment"
                value={form.environment}
                onChange={(event) =>
                  setForm({ ...form, environment: event.target.value as "beta" | "production" })
                }
                className="h-9 rounded-md border border-input bg-background px-3 text-sm"
              >
                <option value="beta">Beta (pruebas)</option>
                <option value="production">Producción</option>
              </select>
            </div>
            {field("api_key", "Clave de API", {
              secret: true,
              hint: settings.data?.has_credentials
                ? "Guardada — déjala vacía para conservarla"
                : "",
            })}
            {field("api_secret", "Secreto de API", {
              secret: true,
              hint: settings.data?.has_credentials
                ? "Guardado — déjalo vacío para conservarlo"
                : "",
            })}
            {field("webhook_secret", "Secreto del webhook", {
              secret: true,
              hint: settings.data?.has_webhook_secret ? "Guardado" : "whsec_…",
            })}
            <div className="grid grid-cols-2 gap-3">
              {field("boleta_series", "Serie de boletas")}
              {field("invoice_series", "Serie de facturas")}
              {field("credit_boleta_series", "Serie de notas de crédito (boletas)")}
              {field("credit_invoice_series", "Serie de notas de crédito (facturas)")}
              {field("dispatch_series", "Serie de guías de remisión")}
            </div>
            <p className="text-xs text-muted-foreground">
              URL del webhook para configurar en eFact:{" "}
              <code>{`${window.location.origin}/api/v1/webhooks/efact/${company.id}`}</code>
            </p>
            <ActionButton
              processing={save.isProcessing}
              processingLabel="Guardando…"
              onClick={() => void save.run()}
            >
              Guardar configuración
            </ActionButton>
          </CardContent>
        </Card>
      )}
    </DashboardLayout>
  );
}

export const Route = createFileRoute("/_app/configuracion/facturacion")({
  component: FiscalSettingsPage,
});
