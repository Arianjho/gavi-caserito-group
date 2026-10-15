import type { ReactNode } from "react";

import { BrandLogo, PoweredBy } from "@/features/brand/brand-logo";
import { Card, CardContent } from "@/components/ui/card";
import type { Brand } from "@/lib/brand";

/** Propiedades de {@link AuthShell}. */
export interface AuthShellProps {
  title: string;
  description: string;
  children: ReactNode;
  /** Marca a mostrar; por defecto la de la cuenta o dominio actual. */
  brand?: Brand;
}

/**
 * Marco de las pantallas públicas de acceso (recuperar y restablecer
 * contraseña): marca, título y tarjeta centrada.
 */
export function AuthShell({ title, description, children, brand }: AuthShellProps) {
  return (
    <div className="flex min-h-screen items-center justify-center bg-background px-4">
      <div className="w-full max-w-sm">
        <div className="mb-6 flex items-center justify-center">
          <BrandLogo size="lg" {...(brand ? { brand } : {})} />
        </div>
        <Card>
          <CardContent className="p-6">
            <h1 className="text-lg font-semibold">{title}</h1>
            <p className="mt-1 text-sm text-muted-foreground">{description}</p>
            <div className="mt-5">{children}</div>
          </CardContent>
        </Card>
        <PoweredBy {...(brand ? { brand } : {})} />
      </div>
    </div>
  );
}
