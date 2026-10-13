import { createFileRoute, Link, useNavigate } from "@tanstack/react-router";
import { useState, type FormEvent } from "react";

import { ActionButton } from "@/components/common/action-button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { AuthShell } from "@/features/auth/auth-shell";
import { useGuardedMutation } from "@/hooks/use-guarded-mutation";
import { api } from "@/lib/api";

/** Parámetros del enlace enviado por correo. */
interface ResetSearch {
  token: string;
  email: string;
}

/**
 * Crea la contraseña nueva con el enlace del correo. Al terminar, todas las
 * sesiones abiertas se cierran y se vuelve al ingreso.
 */
function ResetPasswordPage() {
  const { token, email } = Route.useSearch();
  const navigate = useNavigate();
  const [password, setPassword] = useState("");
  const [confirm, setConfirm] = useState("");

  const reset = useGuardedMutation({
    mutationFn: (input: { password: string }, { signal }) =>
      api("/auth/reset-password", {
        method: "POST",
        body: { email, token, password: input.password },
        signal,
      }),
    successMessage: "Contraseña actualizada. Ya puedes ingresar.",
    onSuccess: () => void navigate({ to: "/login" }),
  });

  const mismatch = confirm !== "" && confirm !== password;

  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (!mismatch && password !== "") void reset.run({ password });
  };

  return (
    <AuthShell title="Crea tu contraseña nueva" description={`Para ${email || "tu cuenta"}.`}>
      <form onSubmit={submit} className="grid gap-4" noValidate>
        <fieldset disabled={reset.isProcessing} className="grid gap-4 border-0 p-0">
          <div className="grid gap-2">
            <Label htmlFor="new-password">Contraseña nueva (mínimo 10 caracteres)</Label>
            <Input
              id="new-password"
              type="password"
              autoComplete="new-password"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              aria-invalid={reset.fieldErrors["password"] !== undefined}
            />
            {reset.fieldErrors["password"]?.[0] && (
              <p className="text-xs text-destructive">{reset.fieldErrors["password"][0]}</p>
            )}
          </div>
          <div className="grid gap-2">
            <Label htmlFor="confirm-password">Repite la contraseña</Label>
            <Input
              id="confirm-password"
              type="password"
              autoComplete="new-password"
              value={confirm}
              onChange={(event) => setConfirm(event.target.value)}
              aria-invalid={mismatch}
            />
            {mismatch && <p className="text-xs text-destructive">Las contraseñas no coinciden.</p>}
          </div>
        </fieldset>
        <ActionButton
          type="submit"
          processing={reset.isProcessing}
          processingLabel="Guardando…"
          className="w-full"
        >
          Guardar contraseña
        </ActionButton>
      </form>
      <p className="mt-4 text-center text-sm">
        <Link to="/olvide-mi-clave" className="text-primary hover:underline">
          Pedir otro enlace
        </Link>
      </p>
    </AuthShell>
  );
}

export const Route = createFileRoute("/restablecer")({
  validateSearch: (search: Record<string, unknown>): ResetSearch => ({
    token: typeof search["token"] === "string" ? search["token"] : "",
    email: typeof search["email"] === "string" ? search["email"] : "",
  }),
  component: ResetPasswordPage,
});
