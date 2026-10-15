import { createFileRoute } from "@tanstack/react-router";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { useEffect, useRef, useState } from "react";

import { ActionButton } from "@/components/common/action-button";
import { StatusChip } from "@/components/common/status-chip";
import { DashboardLayout } from "@/components/dashboard/dashboard-layout";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { useGuardedMutation } from "@/hooks/use-guarded-mutation";
import { api } from "@/lib/api";

/** Estado del dominio propio. */
interface DomainState {
  name: string | null;
  token: string | null;
  verified_at: string | null;
  record?: { type: string; host: string; value: string } | null;
}

/** Marca del negocio tal como la administra el dueño. */
interface Branding {
  brand_name: string | null;
  brand_color: string | null;
  brand_tagline: string | null;
  brand_email: string | null;
  brand_phone: string | null;
  brand_footer: string | null;
  brand_powered_by: boolean;
  logo_url: string | null;
  logo_dark_url: string | null;
  icon_url: string | null;
  domain: DomainState;
}

/** Resultado de enviar el logo a eFact por empresa. */
interface EfactResult {
  company_id: string;
  name: string;
  synced: boolean;
  error: string | null;
}

type Variant = "logo" | "logo_dark" | "icon";

const VARIANTS: { id: Variant; label: string; hint: string; field: keyof Branding }[] = [
  {
    id: "logo",
    label: "Logo principal",
    hint: "PNG, JPG o WebP, mínimo 200 × 40 px, hasta 2 MB. Sale en el panel, el portal, los tickets y el PDF de tus comprobantes.",
    field: "logo_url",
  },
  {
    id: "logo_dark",
    label: "Logo para tema oscuro",
    hint: "Opcional. Se usa cuando el panel está en modo oscuro.",
    field: "logo_dark_url",
  },
  {
    id: "icon",
    label: "Icono cuadrado",
    hint: "PNG o WebP cuadrado, mínimo 128 × 128 px. Es el favicon y el icono del menú contraído.",
    field: "icon_url",
  },
];

/** Campo de texto con su mensaje de error de validación. */
function TextField(props: {
  id: string;
  label: string;
  value: string;
  onChange: (value: string) => void;
  error?: string | undefined;
  placeholder?: string;
  maxLength?: number;
}) {
  return (
    <div className="grid gap-2">
      <Label htmlFor={props.id}>{props.label}</Label>
      <Input
        id={props.id}
        value={props.value}
        maxLength={props.maxLength}
        placeholder={props.placeholder}
        onChange={(event) => props.onChange(event.target.value)}
        aria-invalid={props.error !== undefined}
      />
      {props.error && <p className="text-xs text-destructive">{props.error}</p>}
    </div>
  );
}

/** Identidad: nombre, color, lema, contacto y pie. */
function IdentityCard({ branding }: { branding: Branding }) {
  const queryClient = useQueryClient();
  const [form, setForm] = useState({
    name: "",
    tagline: "",
    email: "",
    phone: "",
    footer: "",
    color: "#2563eb",
    useColor: false,
    poweredBy: false,
  });

  useEffect(() => {
    setForm({
      name: branding.brand_name ?? "",
      tagline: branding.brand_tagline ?? "",
      email: branding.brand_email ?? "",
      phone: branding.brand_phone ?? "",
      footer: branding.brand_footer ?? "",
      color: branding.brand_color ?? "#2563eb",
      useColor: branding.brand_color !== null,
      poweredBy: branding.brand_powered_by,
    });
  }, [branding]);

  const save = useGuardedMutation({
    mutationFn: (_input: void, { signal }) =>
      api("/branding", {
        method: "PUT",
        signal,
        body: {
          brand_name: form.name || null,
          brand_color: form.useColor ? form.color : null,
          brand_tagline: form.tagline || null,
          brand_email: form.email || null,
          brand_phone: form.phone || null,
          brand_footer: form.footer || null,
          brand_powered_by: form.poweredBy,
        },
      }),
    successMessage: "Marca guardada",
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["branding"] });
      void queryClient.invalidateQueries({ queryKey: ["brand"] });
    },
  });
  const set = (name: keyof typeof form) => (value: string) => setForm({ ...form, [name]: value });
  const error = (name: string) => save.fieldErrors[name]?.[0];

  return (
    <Card className="space-y-4 p-4">
      <h2 className="font-semibold">Identidad</h2>
      <TextField
        id="brand-name"
        label="Nombre comercial"
        value={form.name}
        onChange={set("name")}
        error={error("brand_name")}
        placeholder="Juguetería Caserito"
        maxLength={80}
      />
      <TextField
        id="tagline"
        label="Lema (opcional)"
        value={form.tagline}
        onChange={set("tagline")}
        error={error("brand_tagline")}
        placeholder="Juguetes que sacan sonrisas"
        maxLength={120}
      />
      <div className="grid gap-2">
        <label className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            checked={form.useColor}
            onChange={(event) => setForm({ ...form, useColor: event.target.checked })}
          />
          Usar un color de marca en el panel y el portal
        </label>
        {form.useColor && (
          <input
            type="color"
            aria-label="Color de marca"
            value={form.color}
            onChange={(event) => setForm({ ...form, color: event.target.value })}
            className="h-10 w-20 cursor-pointer rounded border border-input"
          />
        )}
      </div>
      <div className="grid gap-3 sm:grid-cols-2">
        <TextField
          id="brand-email"
          label="Correo de contacto"
          value={form.email}
          onChange={set("email")}
          error={error("brand_email")}
        />
        <TextField
          id="brand-phone"
          label="Teléfono de contacto"
          value={form.phone}
          onChange={set("phone")}
          error={error("brand_phone")}
        />
      </div>
      <TextField
        id="brand-footer"
        label="Pie de documentos y portal"
        value={form.footer}
        onChange={set("footer")}
        error={error("brand_footer")}
        placeholder="Gracias por tu compra · Cambios dentro de 7 días"
        maxLength={300}
      />
      <label className="flex items-center gap-2 text-sm">
        <input
          type="checkbox"
          checked={form.poweredBy}
          onChange={(event) => setForm({ ...form, poweredBy: event.target.checked })}
        />
        Mostrar «Con tecnología de Gavi Caserito» en el portal
      </label>
      <ActionButton
        processing={save.isProcessing}
        processingLabel="Guardando…"
        onClick={() => void save.run()}
      >
        Guardar marca
      </ActionButton>
    </Card>
  );
}

/** Subida y vista previa de un archivo de marca. */
function AssetRow({
  variant,
  url,
  onChanged,
  onEfact,
}: {
  variant: (typeof VARIANTS)[number];
  url: string | null;
  onChanged: () => void;
  onEfact: (result: EfactResult[]) => void;
}) {
  const input = useRef<HTMLInputElement>(null);
  const upload = useGuardedMutation({
    mutationFn: async (file: File, { signal }) => {
      const formData = new FormData();
      formData.append("file", file);
      return api<{ data: { efact: EfactResult[] } }>(`/branding/assets/${variant.id}`, {
        method: "POST",
        signal,
        formData,
      });
    },
    successMessage: "Archivo guardado",
    onSuccess: (response) => {
      onEfact(response.data.efact);
      onChanged();
    },
  });
  const remove = useGuardedMutation({
    mutationFn: (_input: void, { signal }) =>
      api(`/branding/assets/${variant.id}`, { method: "DELETE", signal }),
    successMessage: "Archivo eliminado",
    onSuccess: onChanged,
  });
  const error = upload.fieldErrors["file"]?.[0];

  return (
    <div className="flex flex-wrap items-center gap-4 border-t border-border pt-4 first:border-t-0 first:pt-0">
      <div className="grid size-20 shrink-0 place-items-center overflow-hidden rounded-md border bg-muted/40">
        {url ? (
          <img src={url} alt={variant.label} className="max-h-full max-w-full object-contain" />
        ) : (
          <span className="text-[11px] text-muted-foreground">Sin archivo</span>
        )}
      </div>
      <div className="min-w-0 flex-1 space-y-1">
        <p className="text-sm font-medium">{variant.label}</p>
        <p className="text-xs text-muted-foreground">{variant.hint}</p>
        {error && <p className="text-xs text-destructive">{error}</p>}
        <input
          ref={input}
          type="file"
          accept="image/png,image/jpeg,image/webp"
          className="hidden"
          aria-label={`Elegir archivo para ${variant.label}`}
          onChange={(event) => {
            const file = event.target.files?.[0];
            if (file) void upload.run(file);
            event.target.value = "";
          }}
        />
        <div className="flex flex-wrap gap-2 pt-1">
          <ActionButton
            variant="outline"
            size="sm"
            processing={upload.isProcessing}
            processingLabel="Subiendo…"
            onClick={() => input.current?.click()}
          >
            {url ? "Cambiar" : "Subir"}
          </ActionButton>
          {url && (
            <ActionButton
              variant="ghost"
              size="sm"
              processing={remove.isProcessing}
              processingLabel="Quitando…"
              onClick={() => void remove.run()}
            >
              Quitar
            </ActionButton>
          )}
        </div>
      </div>
    </div>
  );
}

/** Logos y estado de sincronización con eFact. */
function AssetsCard({ branding }: { branding: Branding }) {
  const queryClient = useQueryClient();
  const [efact, setEfact] = useState<EfactResult[]>([]);
  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ["branding"] });
    void queryClient.invalidateQueries({ queryKey: ["brand"] });
  };
  const sync = useGuardedMutation({
    mutationFn: (_input: void, { signal }) =>
      api<{ data: EfactResult[] }>("/branding/logo/sync", { method: "POST", signal }),
    onSuccess: (response) => setEfact(response.data),
  });

  return (
    <Card className="space-y-4 p-4">
      <h2 className="font-semibold">Logos</h2>
      {VARIANTS.map((variant) => (
        <AssetRow
          key={variant.id}
          variant={variant}
          url={branding[variant.field] as string | null}
          onChanged={refresh}
          onEfact={setEfact}
        />
      ))}
      <div className="space-y-2 border-t border-border pt-4">
        <p className="text-sm font-medium">PDF de comprobantes y guías (eFact)</p>
        <p className="text-xs text-muted-foreground">
          El logo principal se envía a eFact al subirlo para que salga en la representación impresa.
        </p>
        {efact.map((item) => (
          <p key={item.company_id} className="flex items-center gap-2 text-sm">
            <StatusChip
              label={item.synced ? "Enviado" : "Pendiente"}
              tone={item.synced ? "success" : "warning"}
            />
            <span>{item.name}</span>
            {item.error && <span className="text-xs text-muted-foreground">{item.error}</span>}
          </p>
        ))}
        <ActionButton
          variant="outline"
          size="sm"
          processing={sync.isProcessing}
          processingLabel="Enviando…"
          onClick={() => void sync.run()}
        >
          Reenviar logo a eFact
        </ActionButton>
      </div>
    </Card>
  );
}

/** Dominio propio con su verificación por DNS. */
function DomainCard({ branding }: { branding: Branding }) {
  const queryClient = useQueryClient();
  const [domain, setDomain] = useState("");
  useEffect(() => setDomain(branding.domain.name ?? ""), [branding.domain.name]);
  const refresh = () => void queryClient.invalidateQueries({ queryKey: ["branding"] });

  const saveDomain = useGuardedMutation({
    mutationFn: (_input: void, { signal }) =>
      api("/branding/domain", { method: "PUT", signal, body: { domain: domain.trim() || null } }),
    successMessage: "Dominio guardado",
    onSuccess: refresh,
  });
  const verify = useGuardedMutation({
    mutationFn: (_input: void, { signal }) =>
      api("/branding/domain/verify", { method: "POST", signal }),
    successMessage: "Dominio verificado",
    onSuccess: refresh,
  });
  const current = branding.domain;
  const record = current.name
    ? { host: `_caserito.${current.name}`, value: current.token ?? "" }
    : null;

  return (
    <Card className="space-y-4 p-4">
      <h2 className="font-semibold">Dominio propio</h2>
      <p className="text-xs text-muted-foreground">
        Tu equipo y tus clientes abren el panel y el portal con tu dominio, con tu marca. Primero
        demuestra que es tuyo con un registro DNS.
      </p>
      <TextField
        id="domain"
        label="Dominio"
        value={domain}
        onChange={setDomain}
        error={saveDomain.fieldErrors["domain"]?.[0]}
        placeholder="tienda.minegocio.pe"
      />
      <div className="flex flex-wrap gap-2">
        <ActionButton
          processing={saveDomain.isProcessing}
          processingLabel="Guardando…"
          onClick={() => void saveDomain.run()}
        >
          Guardar dominio
        </ActionButton>
      </div>
      {record && (
        <div className="space-y-2 rounded-md border p-3 text-sm">
          <p className="flex items-center gap-2">
            <StatusChip
              label={current.verified_at ? "Verificado" : "Por verificar"}
              tone={current.verified_at ? "success" : "warning"}
            />
          </p>
          {!current.verified_at && (
            <>
              <p className="text-xs text-muted-foreground">
                En tu proveedor de dominio crea este registro TXT y luego verifica:
              </p>
              <dl className="grid gap-1 text-xs">
                <div className="flex gap-2">
                  <dt className="w-14 text-muted-foreground">Nombre</dt>
                  <dd className="break-all font-mono">{record.host}</dd>
                </div>
                <div className="flex gap-2">
                  <dt className="w-14 text-muted-foreground">Valor</dt>
                  <dd className="break-all font-mono">{record.value}</dd>
                </div>
              </dl>
              <Button
                variant="outline"
                size="sm"
                disabled={verify.isProcessing}
                onClick={() => void verify.run()}
              >
                {verify.isProcessing ? "Verificando…" : "Verificar ahora"}
              </Button>
            </>
          )}
          <p className="text-xs text-muted-foreground">
            Además apunta el dominio (CNAME o A) al servidor de la aplicación; el soporte te indica
            a dónde.
          </p>
        </div>
      )}
    </Card>
  );
}

/**
 * Marca del negocio: identidad, logos, contacto y dominio propio. Lo que ve el
 * equipo en el panel y el cliente en portal, tickets y comprobantes.
 */
function BrandingPage() {
  const current = useQuery({
    queryKey: ["branding"],
    queryFn: async () => (await api<{ data: Branding }>("/branding")).data,
  });

  return (
    <DashboardLayout
      title="Marca"
      description="Tu nombre, logo y colores en el panel, el portal del cliente y los documentos."
    >
      {current.data && (
        <div className="grid max-w-2xl gap-4">
          <IdentityCard branding={current.data} />
          <AssetsCard branding={current.data} />
          <DomainCard branding={current.data} />
        </div>
      )}
    </DashboardLayout>
  );
}

export const Route = createFileRoute("/_app/configuracion/marca")({
  component: BrandingPage,
});
