import { cva, type VariantProps } from "class-variance-authority";

import { cn } from "@/lib/utils";

const chip = cva(
  "inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap",
  {
    variants: {
      tone: {
        neutral: "bg-muted text-muted-foreground",
        info: "bg-info/15 text-info",
        success: "bg-success/15 text-success",
        warning: "bg-warning/20 text-warning-foreground dark:text-warning",
        danger: "bg-destructive/15 text-destructive",
      },
    },
    defaultVariants: { tone: "neutral" },
  },
);

/** Propiedades de {@link StatusChip}. */
export interface StatusChipProps extends VariantProps<typeof chip> {
  /** Texto visible del estado (ya traducido). */
  label: string;
  /** Clases adicionales. */
  className?: string;
}

/**
 * Etiqueta de estado con color semántico.
 *
 * Los colores salen de los tokens del tema (`success`, `warning`, `destructive`,
 * `info`), por lo que respetan la paleta de la empresa y el modo oscuro.
 */
export function StatusChip({ label, tone, className }: StatusChipProps) {
  return <span className={cn(chip({ tone }), className)}>{label}</span>;
}
