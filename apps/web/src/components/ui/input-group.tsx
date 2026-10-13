import * as React from "react";

import { cn } from "@/lib/utils";

/**
 * InputGroup: agrupa addons (texto, iconos, botones, selects) con un input.
 * Los hijos directos se unen visualmente: bordes compartidos y radios en los extremos.
 */
function InputGroup({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="input-group"
      className={cn(
        "flex w-full items-stretch",
        // borde y radio compartidos entre hijos
        "[&>*]:rounded-none",
        "[&>*:first-child]:rounded-l-md [&>*:last-child]:rounded-r-md",
        "[&>*:not(:first-child)]:-ml-px",
        "[&>*:focus-visible]:relative [&>*:focus-visible]:z-10",
        "[&>*:focus-within]:relative [&>*:focus-within]:z-10",
        className,
      )}
      {...props}
    />
  );
}

function InputGroupAddon({ className, ...props }: React.ComponentProps<"span">) {
  return (
    <span
      data-slot="input-group-addon"
      className={cn(
        "inline-flex shrink-0 items-center gap-1.5 border border-input bg-muted px-3 text-sm text-muted-foreground",
        className,
      )}
      {...props}
    />
  );
}

export { InputGroup, InputGroupAddon };
