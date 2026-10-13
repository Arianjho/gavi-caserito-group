import * as React from "react";

import { cn } from "@/lib/utils";

type ButtonGroupProps = React.ComponentProps<"div"> & {
  orientation?: "horizontal" | "vertical";
};

/** Agrupa botones en una sola pieza (segmented control visual). */
function ButtonGroup({ className, orientation = "horizontal", ...props }: ButtonGroupProps) {
  return (
    <div
      data-slot="button-group"
      role="group"
      data-orientation={orientation}
      className={cn(
        "inline-flex",
        orientation === "horizontal" ? "flex-row" : "flex-col",
        "[&>*]:rounded-none [&>*:focus-visible]:relative [&>*:focus-visible]:z-10",
        orientation === "horizontal"
          ? "[&>*:first-child]:rounded-l-md [&>*:last-child]:rounded-r-md [&>*:not(:first-child)]:-ml-px"
          : "[&>*:first-child]:rounded-t-md [&>*:last-child]:rounded-b-md [&>*:not(:first-child)]:-mt-px",
        className,
      )}
      {...props}
    />
  );
}

function ButtonGroupSeparator({ className, ...props }: React.ComponentProps<"div">) {
  return <div className={cn("w-px self-stretch bg-border", className)} {...props} />;
}

export { ButtonGroup, ButtonGroupSeparator };
