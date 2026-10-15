import { Sparkles } from "lucide-react";

import { useBrand } from "@/features/brand/use-brand";
import type { Brand } from "@/lib/brand";
import { cn } from "@/lib/utils";

/** Propiedades de {@link BrandLogo}. */
export interface BrandLogoProps {
  /** Solo el icono (menú lateral colapsado). */
  compact?: boolean;
  /** Tamaño del logo: `lg` para pantallas de acceso. */
  size?: "md" | "lg";
  /** Marca a mostrar; por defecto la de la cuenta o dominio actual. */
  brand?: Brand;
  className?: string;
}

/**
 * Logo de la cuenta; si no subió uno, el icono y el nombre de su marca.
 *
 * Con tema oscuro usa el logo oscuro cuando existe. El producto no se muestra a
 * sí mismo cuando la cuenta personalizó su marca.
 */
export function BrandLogo({
  compact = false,
  size = "md",
  brand: given,
  className,
}: BrandLogoProps) {
  const current = useBrand();
  const brand = given ?? current;
  const height = size === "lg" ? "h-12" : "h-9";

  if (compact && (brand.icon_url ?? brand.logo_url)) {
    return (
      <img
        src={brand.icon_url ?? brand.logo_url ?? ""}
        alt={brand.name}
        className={cn("size-9 shrink-0 rounded-md object-contain", className)}
      />
    );
  }
  if (!compact && brand.logo_url) {
    return (
      <span className={cn("flex min-w-0 items-center", className)}>
        <img
          src={brand.logo_url}
          alt={brand.name}
          className={cn(
            height,
            "max-w-[11rem] object-contain",
            brand.logo_dark_url && "dark:hidden",
          )}
        />
        {brand.logo_dark_url && (
          <img
            src={brand.logo_dark_url}
            alt={brand.name}
            className={cn(height, "hidden max-w-[11rem] object-contain dark:block")}
          />
        )}
      </span>
    );
  }

  const [first, ...rest] = brand.name.split(" ");
  return (
    <span className={cn("flex min-w-0 items-center gap-2.5", className)}>
      <span
        className={cn(
          "grid shrink-0 place-items-center rounded-xl bg-gradient-brand text-primary-foreground",
          size === "lg" ? "size-10" : "size-9",
        )}
      >
        <Sparkles className="size-5" />
      </span>
      {!compact && (
        <span
          className={cn(
            "min-w-0 truncate font-display font-extrabold tracking-tight",
            size === "lg" ? "text-2xl" : "text-lg",
          )}
        >
          {first} {rest.length > 0 && <span className="text-primary">{rest.join(" ")}</span>}
        </span>
      )}
    </span>
  );
}

/**
 * Pie «Con tecnología de …» cuando la cuenta lo mantiene activo.
 */
export function PoweredBy({ brand: given }: { brand?: Brand }) {
  const current = useBrand();
  const brand = given ?? current;
  if (!brand.powered_by) return null;
  return (
    <p className="mt-6 text-center text-xs text-muted-foreground">
      Con tecnología de Gavi Caserito
    </p>
  );
}
