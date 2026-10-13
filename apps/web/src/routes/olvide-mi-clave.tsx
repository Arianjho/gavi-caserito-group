import { createFileRoute, Link } from "@tanstack/react-router";
import { useState, type FormEvent } from "react";

import { ActionButton } from "@/components/common/action-button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { AuthShell } from "@/features/auth/auth-shell";
import { useGuardedMutation } from "@/hooks/use-guarded-mutation";
import { api } from "@/lib/api";

/**
 * Pide el enlace para crear una contraseña nueva. La respuesta es siempre la
 * misma para no revelar qué correos están registrados.
 */
function ForgotPasswordPage() {
  const [email, setEmail] = useState("");
  const [sent, setSent] = useState(false);

  const request = useGuardedMutation({
    mutationFn: (input: { email: string }, { signal }) =>
      api("/auth/forgot-password", { method: "POST", body: input, signal }),
    onSuccess: () => setSent(true),
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    void request.run({ email });
  };

  return (
    <AuthShell
      title="Recupera tu contraseña"
      description="Te enviaremos un enlace al correo con el que ingresas."
    >
      {sent ? (
        <p className="text-sm">
          Si el correo está registrado, te enviamos un enlace. Revisa tu bandeja (y el spam); vale 1
          hora.
        </p>
      ) : (
        <form onSubmit={submit} className="grid gap-4" noValidate>
          <fieldset disabled={request.isProcessing} className="grid gap-2 border-0 p-0">
            <Label htmlFor="forgot-email">Correo</Label>
            <Input
              id="forgot-email"
              type="email"
              autoComplete="username"
              value={email}
              onChange={(event) => setEmail(event.target.value)}
              aria-invalid={request.fieldErrors["email"] !== undefined}
            />
            {request.fieldErrors["email"]?.[0] && (
              <p className="text-xs text-destructive">{request.fieldErrors["email"][0]}</p>
            )}
          </fieldset>
          <ActionButton
            type="submit"
            processing={request.isProcessing}
            processingLabel="Enviando…"
            className="w-full"
          >
            Enviar enlace
          </ActionButton>
        </form>
      )}
      <p className="mt-4 text-center text-sm">
        <Link to="/login" className="text-primary hover:underline">
          Volver a ingresar
        </Link>
      </p>
    </AuthShell>
  );
}

export const Route = createFileRoute("/olvide-mi-clave")({
  component: ForgotPasswordPage,
});
