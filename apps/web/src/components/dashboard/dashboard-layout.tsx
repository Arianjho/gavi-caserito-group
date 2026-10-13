import type { ReactNode } from "react";

/** Propiedades de {@link DashboardLayout}. */
interface DashboardLayoutProps {
  /** Título de la página. */
  title: string;
  /** Descripción breve bajo el título. */
  description?: string;
  /** Acciones principales de la página (botones). */
  actions?: ReactNode;
  /** Contenido de la página. */
  children: ReactNode;
}

/**
 * Cabecera estándar de página (título, descripción y acciones) con su contenido.
 *
 * La estructura de la aplicación (menú y barra superior) la aporta la ruta
 * `_app`; este componente solo ordena el contenido de cada pantalla.
 */
export function DashboardLayout({ title, description, actions, children }: DashboardLayoutProps) {
  return (
    <div className="w-full">
      <header className="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-3 sm:flex sm:flex-wrap sm:items-center sm:justify-between">
        <div className="min-w-0">
          <h1 className="truncate text-xl font-bold sm:text-2xl">{title}</h1>
          {description && <p className="mt-1 text-sm text-muted-foreground">{description}</p>}
        </div>
        {actions && <div className="flex shrink-0 items-center gap-2">{actions}</div>}
      </header>
      <div className="mt-5">{children}</div>
    </div>
  );
}
