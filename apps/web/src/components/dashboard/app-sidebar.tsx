import { Link, useRouterState } from "@tanstack/react-router";
import { ChevronRight, Sparkles } from "lucide-react";
import { useEffect, useState } from "react";

import { navigation, type NavEntry, type NavLeaf } from "@/app/navigation";
import { hasPermission, useSession } from "@/features/auth/use-session";
import { Badge } from "@/components/ui/badge";
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@/components/ui/collapsible";
import {
  Sidebar,
  SidebarContent,
  SidebarFooter,
  SidebarGroup,
  SidebarGroupContent,
  SidebarHeader,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
  SidebarMenuSub,
  SidebarMenuSubButton,
  SidebarMenuSubItem,
  useSidebar,
} from "@/components/ui/sidebar";

const STORAGE_KEY = "caserito-nav-open";

/**
 * Lee los grupos abiertos guardados por el usuario.
 *
 * @returns Conjunto de identificadores de grupo abiertos.
 */
function readOpenGroups(): Set<string> {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    return new Set(raw ? (JSON.parse(raw) as string[]) : []);
  } catch {
    return new Set();
  }
}

/**
 * Indica si algún ítem del grupo corresponde a la ruta activa.
 *
 * @param items Ítems del grupo.
 * @param pathname Ruta actual.
 */
function groupHasActive(items: NavLeaf[], pathname: string): boolean {
  return items.some((item) => item.to !== undefined && item.to === pathname);
}

/**
 * Filtra el menú según los permisos del usuario: oculta ítems sin permiso y
 * grupos que queden vacíos. Mientras se cargan los permisos no muestra ítems con permiso.
 *
 * @param permissions Permisos efectivos del usuario.
 */
function visibleNavigation(permissions: string[] | undefined): NavEntry[] {
  const result: NavEntry[] = [];
  for (const entry of navigation) {
    if (entry.kind === "item") {
      if (hasPermission(permissions, entry.permission)) result.push(entry);
      continue;
    }
    const items = entry.items.filter((item) => hasPermission(permissions, item.permission));
    if (items.length > 0) result.push({ ...entry, items });
  }
  return result;
}

/**
 * Menú lateral con categorías y desplegables.
 *
 * Se construye desde la configuración declarativa de `app/navigation.ts`.
 * El grupo con la ruta activa se abre solo y el resto del estado de apertura
 * se recuerda por usuario.
 */
export function AppSidebar() {
  const { state } = useSidebar();
  const permissions = useSession().data?.permissions;
  const entries = visibleNavigation(permissions);
  const collapsed = state === "collapsed";
  const pathname = useRouterState({ select: (router) => router.location.pathname });
  const [open, setOpen] = useState<Set<string>>(() => readOpenGroups());

  useEffect(() => {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify([...open]));
    } catch {
      /* el almacenamiento puede no estar disponible */
    }
  }, [open]);

  const toggle = (id: string, value: boolean) => {
    setOpen((previous) => {
      const next = new Set(previous);
      if (value) next.add(id);
      else next.delete(id);
      return next;
    });
  };

  return (
    <Sidebar collapsible="icon">
      <SidebarHeader className="px-3 py-4">
        <Link to="/" className="flex items-center gap-2.5">
          <span className="grid size-9 shrink-0 place-items-center rounded-xl bg-gradient-brand text-sidebar-primary-foreground">
            <Sparkles className="size-5" />
          </span>
          {!collapsed && (
            <span className="min-w-0 truncate font-display text-lg font-extrabold tracking-tight">
              Gavi <span className="text-sidebar-primary">Caserito</span>
            </span>
          )}
        </Link>
      </SidebarHeader>

      <SidebarContent>
        <SidebarGroup>
          <SidebarGroupContent>
            <SidebarMenu>
              {entries.map((entry) => (
                <NavEntryView
                  key={entry.kind === "group" ? entry.id : entry.title}
                  entry={entry}
                  pathname={pathname}
                  collapsed={collapsed}
                  isOpen={
                    entry.kind === "group" &&
                    (open.has(entry.id) || groupHasActive(entry.items, pathname))
                  }
                  onToggle={toggle}
                />
              ))}
            </SidebarMenu>
          </SidebarGroupContent>
        </SidebarGroup>
      </SidebarContent>

      <SidebarFooter className="p-3">
        {!collapsed && (
          <p className="px-1 text-xs text-sidebar-foreground/60">Versión de desarrollo</p>
        )}
      </SidebarFooter>
    </Sidebar>
  );
}

interface NavEntryViewProps {
  entry: NavEntry;
  pathname: string;
  collapsed: boolean;
  isOpen: boolean;
  onToggle: (id: string, value: boolean) => void;
}

/**
 * Dibuja un elemento de primer nivel del menú: ítem directo o grupo desplegable.
 */
function NavEntryView({ entry, pathname, collapsed, isOpen, onToggle }: NavEntryViewProps) {
  if (entry.kind === "item") {
    const active = entry.to !== undefined && entry.to === pathname;
    return (
      <SidebarMenuItem>
        <SidebarMenuButton asChild={entry.to !== undefined} isActive={active} tooltip={entry.title}>
          {entry.to !== undefined ? (
            <Link to={entry.to}>
              <entry.icon className="size-4 shrink-0" />
              <span className="min-w-0 truncate">{entry.title}</span>
            </Link>
          ) : (
            <span aria-disabled="true" className="flex w-full items-center gap-2 opacity-60">
              <entry.icon className="size-4 shrink-0" />
              <span className="min-w-0 truncate">{entry.title}</span>
            </span>
          )}
        </SidebarMenuButton>
      </SidebarMenuItem>
    );
  }

  return (
    <Collapsible open={isOpen} onOpenChange={(value) => onToggle(entry.id, value)} asChild>
      <SidebarMenuItem>
        <CollapsibleTrigger asChild>
          <SidebarMenuButton tooltip={entry.title}>
            <entry.icon className="size-4 shrink-0" />
            {!collapsed && <span className="min-w-0 flex-1 truncate">{entry.title}</span>}
            {!collapsed && (
              <ChevronRight
                className={`size-4 shrink-0 transition-transform ${isOpen ? "rotate-90" : ""}`}
              />
            )}
          </SidebarMenuButton>
        </CollapsibleTrigger>
        <CollapsibleContent>
          <SidebarMenuSub>
            {entry.items.map((item) => (
              <SidebarMenuSubItem key={item.title}>
                {item.to !== undefined ? (
                  <SidebarMenuSubButton asChild isActive={pathname === item.to}>
                    <Link to={item.to}>
                      <span className="truncate">{item.title}</span>
                      {item.badge && (
                        <Badge variant="secondary" className="ml-auto h-5 px-1.5 text-[10px]">
                          {item.badge}
                        </Badge>
                      )}
                    </Link>
                  </SidebarMenuSubButton>
                ) : (
                  <SidebarMenuSubButton
                    aria-disabled="true"
                    className="cursor-not-allowed opacity-50"
                  >
                    <span className="truncate">{item.title}</span>
                  </SidebarMenuSubButton>
                )}
              </SidebarMenuSubItem>
            ))}
          </SidebarMenuSub>
        </CollapsibleContent>
      </SidebarMenuItem>
    </Collapsible>
  );
}
