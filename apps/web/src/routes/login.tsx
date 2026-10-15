import { useQueryClient } from "@tanstack/react-query";
import { createFileRoute, Link, redirect, useNavigate } from "@tanstack/react-router";
import { useState, type FormEvent } from "react";

import { ActionButton } from "@/components/common/action-button";
import { BrandLogo, PoweredBy } from "@/features/brand/brand-logo";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { sessionQueryKey } from "@/features/auth/use-session";
import { useGuardedMutation } from "@/hooks/use-guarded-mutation";
import { api, getToken, setToken } from "@/lib/api";

/** Respuesta de `POST /auth/login`. */
interface LoginResponse {
  token: string;
}

/**
 * Pantalla de inicio de sesión.
 *
 * Usa el contrato de interacción: el formulario se bloquea mientras se valida
 * y los errores de credenciales se muestran como advertencia.
 */
function LoginPage() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");

  const login = useGuardedMutation({
    mutationFn: (input: { email: string; password: string }, { signal }) =>
      api<LoginResponse>("/auth/login", { method: "POST", body: input, signal }),
    onSuccess: (result) => {
      setToken(result.token);
      void queryClient.invalidateQueries({ queryKey: sessionQueryKey });
      void navigate({ to: "/" });
    },
  });

  const emailError = login.fieldErrors["email"]?.[0];
  const passwordError = login.fieldErrors["password"]?.[0];

  const submit = (event: FormEvent) => {
    event.preventDefault();
    void login.run({ email, password });
  };

  return (
    <div className="flex min-h-screen items-center justify-center bg-background px-4">
      <div className="w-full max-w-sm">
        <div className="mb-6 flex items-center justify-center">
          <BrandLogo size="lg" />
        </div>
        <Card>
          <CardContent className="p-6">
            <h1 className="text-lg font-semibold">Ingresa a tu cuenta</h1>
            <p className="mt-1 text-sm text-muted-foreground">
              Usa el correo con el que te registraron.
            </p>
            <form onSubmit={submit} className="mt-5 grid gap-4" noValidate>
              <fieldset disabled={login.isProcessing} className="grid gap-4 border-0 p-0">
                <div className="grid gap-2">
                  <Label htmlFor="email">Correo</Label>
                  <Input
                    id="email"
                    type="email"
                    autoComplete="username"
                    value={email}
                    onChange={(event) => setEmail(event.target.value)}
                    aria-invalid={emailError !== undefined}
                  />
                  {emailError && <p className="text-xs text-destructive">{emailError}</p>}
                </div>
                <div className="grid gap-2">
                  <Label htmlFor="password">Contraseña</Label>
                  <Input
                    id="password"
                    type="password"
                    autoComplete="current-password"
                    value={password}
                    onChange={(event) => setPassword(event.target.value)}
                    aria-invalid={passwordError !== undefined}
                  />
                  {passwordError && <p className="text-xs text-destructive">{passwordError}</p>}
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
            <p className="mt-4 text-center text-sm">
              <Link to="/olvide-mi-clave" className="text-primary hover:underline">
                ¿Olvidaste tu contraseña?
              </Link>
            </p>
          </CardContent>
        </Card>
        <PoweredBy />
      </div>
    </div>
  );
}

export const Route = createFileRoute("/login")({
  beforeLoad: () => {
    if (getToken()) throw redirect({ to: "/" });
  },
  component: LoginPage,
});
