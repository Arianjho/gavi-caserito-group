import { createFileRoute, redirect, useNavigate } from "@tanstack/react-router";
import { useState, type FormEvent } from "react";

import { ActionButton } from "@/components/common/action-button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { AuthShell } from "@/features/auth/auth-shell";
import { useGuardedMutation } from "@/hooks/use-guarded-mutation";
import { api, getOperatorToken, setOperatorToken } from "@/lib/api";

/** Ingreso de operadores del proveedor al panel SaaS. */
function OperatorLoginPage() {
  const navigate = useNavigate();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");

  const login = useGuardedMutation({
    mutationFn: (input: { email: string; password: string }, { signal }) =>
      api<{ token: string }>("/operator/auth/login", {
        method: "POST",
        body: input,
        signal,
        scope: "operator",
      }),
    onSuccess: (result) => {
      setOperatorToken(result.token);
      void navigate({ to: "/operador" });
    },
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    void login.run({ email, password });
  };

  return (
    <AuthShell title="Panel del proveedor" description="Acceso solo para operadores de Gavi.">
      <form onSubmit={submit} className="grid gap-4" noValidate>
        <fieldset disabled={login.isProcessing} className="grid gap-4 border-0 p-0">
          <div className="grid gap-2">
            <Label htmlFor="op-email">Correo</Label>
            <Input
              id="op-email"
              type="email"
              autoComplete="username"
              value={email}
              onChange={(event) => setEmail(event.target.value)}
            />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="op-password">Contraseña</Label>
            <Input
              id="op-password"
              type="password"
              autoComplete="current-password"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
            />
          </div>
        </fieldset>
        <ActionButton
          type="submit"
          processing={login.isProcessing}
          processingLabel="Ingresando…"
          className="w-full"
        >
          Ingresar
        </ActionButton>
      </form>
    </AuthShell>
  );
}

export const Route = createFileRoute("/operador/login")({
  beforeLoad: () => {
    if (getOperatorToken()) throw redirect({ to: "/operador" });
  },
  component: OperatorLoginPage,
});
