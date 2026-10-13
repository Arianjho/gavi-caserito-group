import { createFileRoute } from "@tanstack/react-router";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { useEffect, useState } from "react";

import { ActionButton } from "@/components/common/action-button";
import { StatusChip } from "@/components/common/status-chip";
import { DashboardLayout } from "@/components/dashboard/dashboard-layout";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { useGuardedMutation } from "@/hooks/use-guarded-mutation";
import { api } from "@/lib/api";

/** Configuración del canal devuelta por la API (sin la clave). */
interface Settings {
  base_url: string;
  instance: string;
  enabled: boolean;
  has_api_key: boolean;
}

/** Mensaje enviado o en cola. */
interface Message {
  id: string;
  event: string;
  phone: string;
  status: "queued" | "sent" | "failed";
  error: string | null;
  queued_at: string;
  party_name: string | null;
}

const EVENTS: Record<string, string> = {
  sale_receipt: "Comprobante",
  order_status: "Estado de pedido",
  debt_reminder: "Recordatorio de deuda",
  test: "Prueba",
};

const STATUS: Record<Message["status"], { label: string; tone: "success" | "warning" | "danger" }> =
  {
    sent: { label: "Enviado", tone: "success" },
    queued: { label: "En cola", tone: "warning" },
    failed: { label: "Falló", tone: "danger" },
  };

/**
 * Conexión del negocio con WhatsApp (Evolution API) y bitácora de avisos.
 * La clave se guarda cifrada y no se vuelve a mostrar.
 */
function NotificationsPage() {
  const queryClient = useQueryClient();
  const settings = useQuery({
    queryKey: ["notifications", "settings"],
    queryFn: async () => (await api<{ data: Settings | null }>("/notifications/settings")).data,
  });
  const messages = useQuery({
    queryKey: ["notifications", "messages"],
    queryFn: async () => (await api<{ data: Message[] }>("/notifications")).data,
  });

  const [form, setForm] = useState({ base_url: "", instance: "", api_key: "" });
  const [phone, setPhone] = useState("");

  useEffect(() => {
    if (settings.data) {
      setForm((current) => ({
        ...current,
        base_url: settings.data?.base_url ?? current.base_url,
        instance: settings.data?.instance ?? current.instance,
      }));
    }
  }, [settings.data]);

  const save = useGuardedMutation({
    mutationFn: (_input: void, { signal }) =>
      api("/notifications/settings", {
        method: "PUT",
        signal,
        body: { ...form, api_key: form.api_key || null },
      }),
    successMessage: "Configuración guardada",
    onSuccess: () => {
      setForm((current) => ({ ...current, api_key: "" }));
      void queryClient.invalidateQueries({ queryKey: ["notifications"] });
    },
  });

  const test = useGuardedMutation({
    mutationFn: (_input: void, { signal }) =>
      api("/notifications/test", { method: "POST", signal, body: { phone } }),
    successMessage: "Mensaje de prueba enviado a la cola",
    onSuccess: () =>
      void queryClient.invalidateQueries({ queryKey: ["notifications", "messages"] }),
  });

  const error = (name: string) => save.fieldErrors[name]?.[0] ?? test.fieldErrors[name]?.[0];

  return (
    <DashboardLayout
      title="Notificaciones"
      description="Avisa a tus clientes por WhatsApp: comprobantes, pedidos y deudas."
    >
      <div className="space-y-4">
        <Card className="max-w-xl">
          <CardContent className="space-y-4 p-4">
            <div className="grid gap-2">
              <Label htmlFor="n-url">URL de Evolution API</Label>
              <Input
                id="n-url"
                value={form.base_url}
                placeholder="https://evolution.tudominio.com"
                onChange={(event) => setForm({ ...form, base_url: event.target.value })}
                aria-invalid={error("base_url") !== undefined}
              />
              {error("base_url") && <p className="text-xs text-destructive">{error("base_url")}</p>}
            </div>
            <div className="grid gap-2">
              <Label htmlFor="n-instance">Instancia</Label>
              <Input
                id="n-instance"
                value={form.instance}
                onChange={(event) => setForm({ ...form, instance: event.target.value })}
                aria-invalid={error("instance") !== undefined}
              />
            </div>
            <div className="grid gap-2">
              <Label htmlFor="n-key">Clave de API</Label>
              <Input
                id="n-key"
                type="password"
                autoComplete="off"
                value={form.api_key}
                placeholder={
                  settings.data?.has_api_key ? "Guardada — déjala vacía para conservarla" : ""
                }
                onChange={(event) => setForm({ ...form, api_key: event.target.value })}
                aria-invalid={error("api_key") !== undefined}
              />
            </div>
            <ActionButton
              processing={save.isProcessing}
              processingLabel="Guardando…"
              onClick={() => void save.run()}
            >
              Guardar configuración
            </ActionButton>
          </CardContent>
        </Card>

        {settings.data && (
          <Card className="max-w-xl">
            <CardContent className="space-y-3 p-4">
              <h2 className="text-sm font-semibold">Enviar mensaje de prueba</h2>
              <div className="flex gap-2">
                <Input
                  aria-label="Celular de prueba"
                  inputMode="tel"
                  placeholder="987 654 321"
                  value={phone}
                  onChange={(event) => setPhone(event.target.value)}
                />
                <ActionButton
                  variant="outline"
                  disabled={phone.trim() === ""}
                  processing={test.isProcessing}
                  processingLabel="Enviando…"
                  onClick={() => void test.run()}
                >
                  Probar
                </ActionButton>
              </div>
              {test.fieldErrors["phone"]?.[0] && (
                <p className="text-xs text-destructive">{test.fieldErrors["phone"][0]}</p>
              )}
            </CardContent>
          </Card>
        )}

        {messages.data && messages.data.length > 0 && (
          <Card className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Fecha</TableHead>
                  <TableHead>Aviso</TableHead>
                  <TableHead>Cliente</TableHead>
                  <TableHead>Celular</TableHead>
                  <TableHead>Estado</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {messages.data.map((message) => (
                  <TableRow key={message.id}>
                    <TableCell className="whitespace-nowrap">
                      {new Date(message.queued_at).toLocaleString("es-PE")}
                    </TableCell>
                    <TableCell>{EVENTS[message.event] ?? message.event}</TableCell>
                    <TableCell>{message.party_name ?? "—"}</TableCell>
                    <TableCell className="tabular-nums">{message.phone}</TableCell>
                    <TableCell>
                      <StatusChip
                        label={STATUS[message.status].label}
                        tone={STATUS[message.status].tone}
                      />
                      {message.error && (
                        <span className="ml-2 text-xs text-muted-foreground">{message.error}</span>
                      )}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </Card>
        )}
      </div>
    </DashboardLayout>
  );
}

export const Route = createFileRoute("/_app/configuracion/notificaciones")({
  component: NotificationsPage,
});
