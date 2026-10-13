import * as React from "react";
import { Bold, Italic, Link2, List, ListOrdered, Quote, Code2, Heading2 } from "lucide-react";

import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Separator } from "@/components/ui/separator";
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from "@/components/ui/tooltip";

type Action = {
  icon: typeof Bold;
  label: string;
  prefix: string;
  suffix?: string;
  block?: boolean;
};

const actions: Action[] = [
  { icon: Bold, label: "Negrita", prefix: "**", suffix: "**" },
  { icon: Italic, label: "Cursiva", prefix: "_", suffix: "_" },
  { icon: Code2, label: "Código", prefix: "`", suffix: "`" },
  { icon: Link2, label: "Enlace", prefix: "[", suffix: "](https://)" },
  { icon: Heading2, label: "Título", prefix: "## ", block: true },
  { icon: List, label: "Lista", prefix: "- ", block: true },
  { icon: ListOrdered, label: "Lista numerada", prefix: "1. ", block: true },
  { icon: Quote, label: "Cita", prefix: "> ", block: true },
];

type RichTextareaProps = {
  value: string;
  onValueChange: (value: string) => void;
  placeholder?: string;
  rows?: number;
  maxLength?: number;
  className?: string;
  id?: string;
};

/** Editor de texto (markdown ligero) con toolbar y contador. */
export function RichTextarea({
  value,
  onValueChange,
  placeholder = "Escribe aquí...",
  rows = 8,
  maxLength = 2000,
  className,
  id,
}: RichTextareaProps) {
  const ref = React.useRef<HTMLTextAreaElement>(null);

  const applyAction = (action: Action) => {
    const el = ref.current;
    if (!el) return;
    const start = el.selectionStart;
    const end = el.selectionEnd;
    const selected = value.slice(start, end);

    let next: string;
    let cursor: number;

    if (action.block) {
      const lineStart = value.lastIndexOf("\n", start - 1) + 1;
      next = `${value.slice(0, lineStart)}${action.prefix}${value.slice(lineStart)}`;
      cursor = end + action.prefix.length;
    } else {
      const suffix = action.suffix ?? action.prefix;
      next = `${value.slice(0, start)}${action.prefix}${selected}${suffix}${value.slice(end)}`;
      cursor = start + action.prefix.length + selected.length;
    }

    onValueChange(next.slice(0, maxLength));
    requestAnimationFrame(() => {
      el.focus();
      el.setSelectionRange(cursor, cursor);
    });
  };

  return (
    <div
      className={cn(
        "overflow-hidden rounded-md border border-input bg-background focus-within:ring-[3px] focus-within:ring-ring/50",
        className,
      )}
    >
      <TooltipProvider delayDuration={200}>
        <div className="flex flex-wrap items-center gap-0.5 border-b border-border bg-muted/40 p-1">
          {actions.map((action, index) => (
            <React.Fragment key={action.label}>
              {index === 4 ? <Separator orientation="vertical" className="mx-1 h-5" /> : null}
              <Tooltip>
                <TooltipTrigger asChild>
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-8"
                    aria-label={action.label}
                    onClick={() => applyAction(action)}
                  >
                    <action.icon className="size-4" />
                  </Button>
                </TooltipTrigger>
                <TooltipContent>{action.label}</TooltipContent>
              </Tooltip>
            </React.Fragment>
          ))}
        </div>
      </TooltipProvider>

      <textarea
        id={id}
        ref={ref}
        rows={rows}
        value={value}
        maxLength={maxLength}
        placeholder={placeholder}
        onChange={(event) => onValueChange(event.target.value)}
        className="w-full resize-y bg-transparent px-3 py-2 text-sm outline-none placeholder:text-muted-foreground"
      />

      <div className="flex items-center justify-between border-t border-border bg-muted/30 px-3 py-1.5 text-xs text-muted-foreground">
        <span>Soporta markdown ligero</span>
        <span>
          {value.length}/{maxLength}
        </span>
      </div>
    </div>
  );
}
