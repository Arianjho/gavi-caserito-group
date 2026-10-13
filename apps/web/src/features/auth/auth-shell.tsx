import { Sparkles } from "lucide-react";
import type { ReactNode } from "react";

import { Card, CardContent } from "@/components/ui/card";

/** Propiedades de {@link AuthShell}. */
export interface AuthShellProps {
  title: string;
  description: string;
  children: ReactNode;
}

/**
 * Marco de las pantallas públicas de acceso (recuperar y restablecer
 * contraseña): marca, título y tarjeta centrada.
 */
export function AuthShell({ title, description, children }: AuthShellProps) {
  return (
    <div className="flex min-h-screen items-center justify-center bg-background px-4">
      <div className="w-full max-w-sm">
        <div className="mb-6 flex items-center justify-center gap-2.5">
          <span className="grid size-10 place-items-center rounded-xl bg-gradient-brand text-primary-foreground">
            <Sparkles className="size-5" />
          </span>
          <span className="font-display text-2xl font-extrabold tracking-tight">
            Gavi <span className="text-primary">Caserito</span>
          </span>
        </div>
        <Card>
          <CardContent className="p-6">
            <h1 className="text-lg font-semibold">{title}</h1>
            <p className="mt-1 text-sm text-muted-foreground">{description}</p>
            <div className="mt-5">{children}</div>
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
