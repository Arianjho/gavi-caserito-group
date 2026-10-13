import { Link, useNavigate } from "@tanstack/react-router";
import {
  Bell,
  Check,
  ChevronsUpDown,
  LogOut,
  Moon,
  Palette,
  Search,
  Sun,
  User,
} from "lucide-react";
import { useEffect, useState } from "react";
import { useQueryClient } from "@tanstack/react-query";

import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  CommandDialog,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from "@/components/ui/command";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Separator } from "@/components/ui/separator";
import { SidebarTrigger } from "@/components/ui/sidebar";
import { useTheme } from "@/components/theme-provider";
import { useSession } from "@/features/auth/use-session";
import { api, setToken } from "@/lib/api";

/** Empresas de ejemplo mientras no existe la API de plataforma. */
const companies = ["Mi Juguetería SAC", "Distribuidora Norte EIRL"];
/** Sucursales de ejemplo mientras no existe la API de plataforma. */
const branches = ["Sucursal Centro", "Sucursal Norte"];

/**
 * Barra superior de la aplicación.
 *
 * Contiene el selector de empresa y sucursal, la búsqueda global (Ctrl+K),
 * el cambio de tema, las notificaciones y el menú del usuario.
 * Los datos de empresa y sucursal son provisionales.
 */
export function TopNav() {
  const { theme, toggleTheme } = useTheme();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const session = useSession();
  const user = session.data?.user;
  const initials = (user?.name ?? "?")
    .split(" ")
    .map((part) => part[0])
    .slice(0, 2)
    .join("")
    .toUpperCase();

  const logout = async () => {
    try {
      await api("/auth/logout", { method: "POST" });
    } catch {
      /* se cierra la sesión local aunque falle la revocación */
    }
    setToken(null);
    queryClient.clear();
    void navigate({ to: "/login" });
  };
  const [company, setCompany] = useState<string>(companies[0]!);
  const [branch, setBranch] = useState<string>(branches[0]!);
  const [openCommand, setOpenCommand] = useState(false);

  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if (event.key === "k" && (event.metaKey || event.ctrlKey)) {
        event.preventDefault();
        setOpenCommand((value) => !value);
      }
    };
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, []);

  const go = (to: string) => {
    setOpenCommand(false);
    void navigate({ to });
  };

  return (
    <header className="sticky top-0 z-30 border-b border-border bg-surface/80 backdrop-blur">
      <div className="flex items-center gap-2 px-3 py-2 sm:gap-3 sm:px-5">
        <SidebarTrigger />
        <Separator orientation="vertical" className="hidden h-6 sm:block" />

        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button variant="ghost" className="min-w-0 gap-2 px-2">
              <span className="grid size-7 shrink-0 place-items-center rounded-md bg-gradient-brand text-xs font-bold text-brand-foreground">
                {company.slice(0, 1)}
              </span>
              <span className="min-w-0 text-left">
                <span className="block truncate text-sm font-semibold leading-tight">
                  {company}
                </span>
                <span className="block truncate text-[11px] leading-tight text-muted-foreground">
                  {branch}
                </span>
              </span>
              <ChevronsUpDown className="size-4 shrink-0 text-muted-foreground" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="start" className="w-64">
            <DropdownMenuLabel>Empresa</DropdownMenuLabel>
            {companies.map((item) => (
              <DropdownMenuItem key={item} onSelect={() => setCompany(item)}>
                <span className="flex-1 truncate">{item}</span>
                {item === company && <Check className="size-4" />}
              </DropdownMenuItem>
            ))}
            <DropdownMenuSeparator />
            <DropdownMenuLabel>Sucursal</DropdownMenuLabel>
            {branches.map((item) => (
              <DropdownMenuItem key={item} onSelect={() => setBranch(item)}>
                <span className="flex-1 truncate">{item}</span>
                {item === branch && <Check className="size-4" />}
              </DropdownMenuItem>
            ))}
          </DropdownMenuContent>
        </DropdownMenu>

        <button
          type="button"
          onClick={() => setOpenCommand(true)}
          className="hidden h-9 flex-1 items-center gap-2 rounded-lg border border-border bg-background px-3 text-sm text-muted-foreground transition-colors hover:border-ring md:flex"
        >
          <Search className="size-4" />
          <span className="flex-1 text-left">Buscar productos, clientes, pedidos…</span>
          <kbd className="rounded border border-border px-1.5 py-0.5 text-[10px]">Ctrl K</kbd>
        </button>

        <div className="ml-auto flex items-center gap-1">
          <Button
            variant="ghost"
            size="icon"
            className="md:hidden"
            aria-label="Buscar"
            onClick={() => setOpenCommand(true)}
          >
            <Search className="size-4" />
          </Button>

          <Button variant="ghost" size="icon" aria-label="Cambiar tema" onClick={toggleTheme}>
            {theme === "dark" ? <Sun className="size-4" /> : <Moon className="size-4" />}
          </Button>

          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" size="icon" className="relative" aria-label="Notificaciones">
                <Bell className="size-4" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-72">
              <DropdownMenuLabel className="flex items-center justify-between">
                Notificaciones <Badge variant="secondary">0</Badge>
              </DropdownMenuLabel>
              <DropdownMenuSeparator />
              <p className="px-2 py-3 text-sm text-muted-foreground">No tienes avisos nuevos.</p>
            </DropdownMenuContent>
          </DropdownMenu>

          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <button
                aria-label="Menú de usuario"
                className="rounded-full ring-offset-2 ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              >
                <Avatar className="size-8">
                  <AvatarFallback className="bg-secondary text-secondary-foreground">
                    {initials}
                  </AvatarFallback>
                </Avatar>
              </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-56">
              <DropdownMenuLabel className="flex flex-col">
                <span className="text-sm font-semibold">{user?.name ?? "…"}</span>
                <span className="text-xs font-normal text-muted-foreground">{user?.email}</span>
              </DropdownMenuLabel>
              <DropdownMenuSeparator />
              <DropdownMenuItem disabled>
                <User className="size-4" /> Mi perfil
              </DropdownMenuItem>
              <DropdownMenuItem asChild>
                <Link to="/design-system">
                  <Palette className="size-4" /> Catálogo de componentes
                </Link>
              </DropdownMenuItem>
              <DropdownMenuSeparator />
              <DropdownMenuItem className="text-destructive" onSelect={() => void logout()}>
                <LogOut className="size-4" /> Cerrar sesión
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </div>

      <CommandDialog open={openCommand} onOpenChange={setOpenCommand}>
        <CommandInput placeholder="Buscar páginas y acciones…" />
        <CommandList>
          <CommandEmpty>Sin resultados.</CommandEmpty>
          <CommandGroup heading="Navegación">
            <CommandItem onSelect={() => go("/")}>Inicio</CommandItem>
            <CommandItem onSelect={() => go("/design-system")}>Catálogo de componentes</CommandItem>
            <CommandItem onSelect={() => go("/dev/interaccion")}>
              Contrato de interacción
            </CommandItem>
          </CommandGroup>
        </CommandList>
      </CommandDialog>
    </header>
  );
}
