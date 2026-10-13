import { createFileRoute } from "@tanstack/react-router";
import { useState } from "react";
import {
  AlignCenter,
  AlignLeft,
  AlignRight,
  Bell,
  Copy,
  Info,
  Mail,
  Search,
  Star,
  Sparkles,
  TriangleAlert,
} from "lucide-react";
import { toast } from "sonner";

import { DashboardLayout } from "@/components/dashboard/dashboard-layout";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Checkbox } from "@/components/ui/checkbox";
import { Switch } from "@/components/ui/switch";
import { Slider } from "@/components/ui/slider";
import { Progress } from "@/components/ui/progress";
import { Skeleton } from "@/components/ui/skeleton";
import { Separator } from "@/components/ui/separator";
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from "@/components/ui/tooltip";
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from "@/components/ui/accordion";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from "@/components/ui/alert-dialog";
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from "@/components/ui/sheet";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { Combobox, MultiSelect } from "@/components/ui/combobox";
import { InputGroup, InputGroupAddon } from "@/components/ui/input-group";
import { ButtonGroup } from "@/components/ui/button-group";
import { RichTextarea } from "@/components/ui/rich-textarea";
import {
  Breadcrumb,
  BreadcrumbItem,
  BreadcrumbLink,
  BreadcrumbList,
  BreadcrumbPage,
  BreadcrumbSeparator,
} from "@/components/ui/breadcrumb";

export const Route = createFileRoute("/_app/design-system")({
  component: DesignSystemPage,
});

const colorTokens = [
  "background",
  "surface",
  "foreground",
  "card",
  "primary",
  "secondary",
  "muted",
  "accent",
  "success",
  "warning",
  "info",
  "destructive",
  "border",
  "ring",
  "sidebar",
  "chart-1",
  "chart-2",
  "chart-3",
  "chart-4",
  "chart-5",
];

const paisesOptions = [
  { value: "pe", label: "Perú" },
  { value: "mx", label: "México" },
  { value: "co", label: "Colombia" },
  { value: "cl", label: "Chile" },
  { value: "ar", label: "Argentina" },
  { value: "es", label: "España" },
];

const tagsOptions = [
  { value: "diseno", label: "Diseño" },
  { value: "frontend", label: "Frontend", description: "React, Tailwind" },
  { value: "backend", label: "Backend" },
  { value: "marketing", label: "Marketing" },
  { value: "soporte", label: "Soporte" },
  { value: "ventas", label: "Ventas" },
];

function DesignSystemPage() {
  const [slider, setSlider] = useState([60]);
  const [pais, setPais] = useState("pe");
  const [tags, setTags] = useState<string[]>(["frontend", "diseno"]);
  const [rango, setRango] = useState<"dia" | "semana" | "mes">("semana");
  const [editor, setEditor] = useState(
    "## Nota interna\n\nEscribe aquí el **detalle** del registro.",
  );

  return (
    <DashboardLayout
      title="Design System"
      description="Todos los tokens y componentes de la plantilla. Edita src/styles.css para re-marcar el panel."
      actions={
        <Button
          variant="outline"
          className="gap-2"
          onClick={() => {
            void navigator.clipboard?.writeText("src/styles.css");
            toast.success("Ruta copiada: src/styles.css");
          }}
        >
          <Copy className="size-4" /> src/styles.css
        </Button>
      }
    >
      <div className="space-y-4">
        <Card className="shadow-soft">
          <CardHeader>
            <CardTitle>Tokens de color</CardTitle>
          </CardHeader>
          <CardContent>
            <p className="mb-4 text-sm text-muted-foreground">
              Cada muestra lee su valor desde la variable CSS. Cambia <code>:root</code> (light) y{" "}
              <code>.dark</code> en <code>src/styles.css</code> y toda la UI se actualiza.
            </p>
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-5">
              {colorTokens.map((token) => (
                <div key={token} className="rounded-xl border border-border p-2">
                  <div
                    className="h-14 w-full rounded-lg border border-border"
                    style={{ backgroundColor: `var(--${token})` }}
                  />
                  <p className="mt-2 truncate text-xs font-medium">--{token}</p>
                </div>
              ))}
            </div>
          </CardContent>
        </Card>

        <div className="grid gap-4 lg:grid-cols-2">
          <Card className="shadow-soft">
            <CardHeader>
              <CardTitle>Tipografía</CardTitle>
            </CardHeader>
            <CardContent className="space-y-2">
              <h1 className="text-4xl font-extrabold">Display / Outfit 800</h1>
              <h2 className="text-2xl font-bold">Título de sección</h2>
              <h3 className="text-lg font-semibold">Subtítulo</h3>
              <p className="text-sm text-muted-foreground">
                Cuerpo en DM Sans. Ideal para tablas densas, formularios y descripciones largas
                dentro del panel administrable.
              </p>
              <p className="font-mono text-xs">mono / código: REG-1042</p>
            </CardContent>
          </Card>

          <Card className="shadow-soft">
            <CardHeader>
              <CardTitle>Radios y sombras</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="flex flex-wrap gap-3">
                {[
                  "rounded-sm",
                  "rounded-md",
                  "rounded-lg",
                  "rounded-xl",
                  "rounded-2xl",
                  "rounded-full",
                ].map((radius) => (
                  <div
                    key={radius}
                    className={`grid size-20 place-items-center border border-border bg-muted text-[10px] ${radius}`}
                  >
                    {radius}
                  </div>
                ))}
              </div>
              <Separator />
              <div className="flex flex-wrap gap-4">
                <div className="grid h-20 w-32 place-items-center rounded-xl bg-surface shadow-soft text-xs">
                  shadow-soft
                </div>
                <div className="grid h-20 w-32 place-items-center rounded-xl bg-surface shadow-high text-xs">
                  shadow-high
                </div>
                <div className="grid h-20 w-32 place-items-center rounded-xl bg-gradient-brand text-xs text-brand-foreground">
                  gradient-brand
                </div>
              </div>
            </CardContent>
          </Card>
        </div>

        <Card className="shadow-soft">
          <CardHeader>
            <CardTitle>Componentes básicos</CardTitle>
          </CardHeader>
          <CardContent className="space-y-6">
            <section className="space-y-3">
              <h3 className="text-sm font-semibold text-muted-foreground">Botones</h3>
              <div className="flex flex-wrap gap-2">
                <Button>Primario</Button>
                <Button variant="secondary">Secundario</Button>
                <Button variant="outline">Outline</Button>
                <Button variant="ghost">Ghost</Button>
                <Button variant="link">Link</Button>
                <Button variant="destructive">Destructivo</Button>
                <Button className="bg-gradient-brand text-brand-foreground hover:opacity-90">
                  <Sparkles className="size-4" /> Premium
                </Button>
                <Button size="sm">Small</Button>
                <Button size="lg">Large</Button>
                <Button size="icon" aria-label="Notificaciones">
                  <Bell className="size-4" />
                </Button>
                <Button disabled>Deshabilitado</Button>
              </div>
            </section>

            <section className="space-y-3">
              <h3 className="text-sm font-semibold text-muted-foreground">Badges y avatares</h3>
              <div className="flex flex-wrap items-center gap-2">
                <Badge>Default</Badge>
                <Badge variant="secondary">Secundario</Badge>
                <Badge variant="outline">Outline</Badge>
                <Badge variant="destructive">Destructivo</Badge>
                <Badge className="border-success/30 bg-success/12 text-success" variant="outline">
                  Activo
                </Badge>
                <Badge className="border-warning/30 bg-warning/15 text-warning" variant="outline">
                  Pendiente
                </Badge>
                <Avatar>
                  <AvatarFallback>AB</AvatarFallback>
                </Avatar>
                <TooltipProvider>
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <Button variant="outline" size="icon" aria-label="Favorito">
                        <Star className="size-4" />
                      </Button>
                    </TooltipTrigger>
                    <TooltipContent>Tooltip de ejemplo</TooltipContent>
                  </Tooltip>
                </TooltipProvider>
              </div>
            </section>

            <section className="grid gap-4 md:grid-cols-2">
              <div className="space-y-3">
                <h3 className="text-sm font-semibold text-muted-foreground">Formulario</h3>
                <div className="grid gap-2">
                  <Label htmlFor="ds-input">Input</Label>
                  <Input id="ds-input" placeholder="Escribe algo…" />
                </div>
                <div className="grid gap-2">
                  <Label htmlFor="ds-textarea">Textarea</Label>
                  <Textarea id="ds-textarea" placeholder="Descripción…" />
                </div>
                <div className="grid gap-2">
                  <Label>Select</Label>
                  <Select defaultValue="pro">
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="free">Free</SelectItem>
                      <SelectItem value="pro">Pro</SelectItem>
                      <SelectItem value="business">Business</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              </div>

              <div className="space-y-4">
                <h3 className="text-sm font-semibold text-muted-foreground">Controles</h3>
                <div className="flex items-center gap-2">
                  <Checkbox id="ds-check" defaultChecked />
                  <Label htmlFor="ds-check">Checkbox</Label>
                </div>
                <div className="flex items-center gap-2">
                  <Switch id="ds-switch" defaultChecked />
                  <Label htmlFor="ds-switch">Switch</Label>
                </div>
                <RadioGroup defaultValue="a" className="flex gap-4">
                  <div className="flex items-center gap-2">
                    <RadioGroupItem value="a" id="ds-r1" />
                    <Label htmlFor="ds-r1">Opción A</Label>
                  </div>
                  <div className="flex items-center gap-2">
                    <RadioGroupItem value="b" id="ds-r2" />
                    <Label htmlFor="ds-r2">Opción B</Label>
                  </div>
                </RadioGroup>
                <div className="space-y-2">
                  <Label>Slider ({slider[0]})</Label>
                  <Slider value={slider} onValueChange={setSlider} max={100} step={1} />
                </div>
                <div className="space-y-2">
                  <Label>Progress</Label>
                  <Progress value={slider[0]} />
                </div>
                <div className="space-y-2">
                  <Label>Skeleton</Label>
                  <Skeleton className="h-8 w-full" />
                </div>
              </div>
            </section>

            <section className="space-y-3">
              <h3 className="text-sm font-semibold text-muted-foreground">Alertas y navegación</h3>
              <Alert>
                <Info className="size-4" />
                <AlertTitle>Alerta informativa</AlertTitle>
                <AlertDescription>Usa alertas para estados no bloqueantes.</AlertDescription>
              </Alert>
              <Alert variant="destructive">
                <TriangleAlert className="size-4" />
                <AlertTitle>Algo salió mal</AlertTitle>
                <AlertDescription>Revisa los campos marcados en rojo.</AlertDescription>
              </Alert>
              <Breadcrumb>
                <BreadcrumbList>
                  <BreadcrumbItem>
                    <BreadcrumbLink href="/">Inicio</BreadcrumbLink>
                  </BreadcrumbItem>
                  <BreadcrumbSeparator />
                  <BreadcrumbItem>
                    <BreadcrumbPage>Design System</BreadcrumbPage>
                  </BreadcrumbItem>
                </BreadcrumbList>
              </Breadcrumb>
              <Tabs defaultValue="uno">
                <TabsList>
                  <TabsTrigger value="uno">Pestaña 1</TabsTrigger>
                  <TabsTrigger value="dos">Pestaña 2</TabsTrigger>
                </TabsList>
                <TabsContent value="uno" className="text-sm text-muted-foreground">
                  Contenido de la primera pestaña.
                </TabsContent>
                <TabsContent value="dos" className="text-sm text-muted-foreground">
                  Contenido de la segunda pestaña.
                </TabsContent>
              </Tabs>
            </section>
          </CardContent>
        </Card>

        <Card className="shadow-soft">
          <CardHeader>
            <CardTitle>Formularios avanzados</CardTitle>
          </CardHeader>
          <CardContent className="space-y-6">
            <section className="grid gap-4 md:grid-cols-2">
              <div className="space-y-2">
                <Label>Select con buscador</Label>
                <Combobox
                  options={paisesOptions}
                  value={pais}
                  onValueChange={setPais}
                  placeholder="Selecciona un país"
                  searchPlaceholder="Buscar país..."
                />
              </div>
              <div className="space-y-2">
                <Label>Multiselect con buscador</Label>
                <MultiSelect
                  options={tagsOptions}
                  value={tags}
                  onValueChange={setTags}
                  placeholder="Selecciona etiquetas"
                  searchPlaceholder="Buscar etiqueta..."
                />
              </div>
            </section>

            <Separator />

            <section className="space-y-3">
              <p className="text-sm font-medium">Input groups</p>
              <div className="grid gap-3 md:grid-cols-2">
                <InputGroup>
                  <InputGroupAddon>https://</InputGroupAddon>
                  <Input placeholder="mi-dominio" className="flex-1" />
                  <InputGroupAddon>.com</InputGroupAddon>
                </InputGroup>

                <InputGroup>
                  <InputGroupAddon>
                    <Search className="size-4" />
                  </InputGroupAddon>
                  <Input placeholder="Buscar registros" className="flex-1" />
                  <Button variant="secondary">Buscar</Button>
                </InputGroup>

                <InputGroup>
                  <InputGroupAddon>
                    <Mail className="size-4" />
                  </InputGroupAddon>
                  <Input type="email" placeholder="correo@empresa.com" className="flex-1" />
                </InputGroup>

                <InputGroup>
                  <Input placeholder="Cantidad" className="flex-1" />
                  <InputGroupAddon>USD</InputGroupAddon>
                  <Button variant="outline">
                    <Copy className="size-4" />
                  </Button>
                </InputGroup>
              </div>
            </section>

            <Separator />

            <section className="space-y-3">
              <p className="text-sm font-medium">Button groups</p>
              <div className="flex flex-wrap items-start gap-4">
                <ButtonGroup>
                  {(["dia", "semana", "mes"] as const).map((option) => (
                    <Button
                      key={option}
                      variant={rango === option ? "default" : "outline"}
                      onClick={() => setRango(option)}
                      className="capitalize"
                    >
                      {option}
                    </Button>
                  ))}
                </ButtonGroup>

                <ButtonGroup>
                  <Button variant="outline" size="icon" aria-label="Alinear izquierda">
                    <AlignLeft className="size-4" />
                  </Button>
                  <Button variant="outline" size="icon" aria-label="Centrar">
                    <AlignCenter className="size-4" />
                  </Button>
                  <Button variant="outline" size="icon" aria-label="Alinear derecha">
                    <AlignRight className="size-4" />
                  </Button>
                </ButtonGroup>

                <ButtonGroup orientation="vertical">
                  <Button variant="outline">Arriba</Button>
                  <Button variant="outline">Medio</Button>
                  <Button variant="outline">Abajo</Button>
                </ButtonGroup>
              </div>
            </section>

            <Separator />

            <section className="space-y-3">
              <Label htmlFor="editor">Editor de texto</Label>
              <RichTextarea id="editor" value={editor} onValueChange={setEditor} />
            </section>
          </CardContent>
        </Card>

        <Card className="shadow-soft">
          <CardHeader>
            <CardTitle>Componentes avanzados</CardTitle>
          </CardHeader>
          <CardContent className="space-y-6">
            <div className="flex flex-wrap gap-2">
              <Dialog>
                <DialogTrigger asChild>
                  <Button variant="outline">Modal con formulario</Button>
                </DialogTrigger>
                <DialogContent>
                  <DialogHeader>
                    <DialogTitle>Crear elemento</DialogTitle>
                    <DialogDescription>Modal estándar para acciones de creación.</DialogDescription>
                  </DialogHeader>
                  <div className="grid gap-2">
                    <Label htmlFor="ds-modal">Nombre</Label>
                    <Input id="ds-modal" placeholder="Nombre del elemento" />
                  </div>
                  <DialogFooter>
                    <Button onClick={() => toast.success("Guardado (demo)")}>Guardar</Button>
                  </DialogFooter>
                </DialogContent>
              </Dialog>

              <AlertDialog>
                <AlertDialogTrigger asChild>
                  <Button variant="destructive">Modal de confirmación</Button>
                </AlertDialogTrigger>
                <AlertDialogContent>
                  <AlertDialogHeader>
                    <AlertDialogTitle>¿Confirmas la acción?</AlertDialogTitle>
                    <AlertDialogDescription>
                      Esta acción es destructiva y no se puede deshacer.
                    </AlertDialogDescription>
                  </AlertDialogHeader>
                  <AlertDialogFooter>
                    <AlertDialogCancel>Cancelar</AlertDialogCancel>
                    <AlertDialogAction
                      className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                      onClick={() => toast.success("Acción confirmada")}
                    >
                      Confirmar
                    </AlertDialogAction>
                  </AlertDialogFooter>
                </AlertDialogContent>
              </AlertDialog>

              <Sheet>
                <SheetTrigger asChild>
                  <Button variant="outline">Panel lateral</Button>
                </SheetTrigger>
                <SheetContent>
                  <SheetHeader>
                    <SheetTitle>Panel lateral</SheetTitle>
                    <SheetDescription>Útil para filtros complejos o detalles.</SheetDescription>
                  </SheetHeader>
                </SheetContent>
              </Sheet>

              <Popover>
                <PopoverTrigger asChild>
                  <Button variant="outline">Popover</Button>
                </PopoverTrigger>
                <PopoverContent className="text-sm">
                  Contenido flotante con tokens del sistema.
                </PopoverContent>
              </Popover>

              <Button variant="outline" onClick={() => toast.success("Toast de éxito")}>
                Toast
              </Button>
            </div>

            <Accordion type="single" collapsible>
              <AccordionItem value="a">
                <AccordionTrigger>¿Cómo cambio la paleta?</AccordionTrigger>
                <AccordionContent>
                  Edita las variables en <code>:root</code> y <code>.dark</code> dentro de{" "}
                  <code>src/styles.css</code>. No hace falta tocar componentes.
                </AccordionContent>
              </AccordionItem>
              <AccordionItem value="b">
                <AccordionTrigger>¿Cómo agrego un color nuevo?</AccordionTrigger>
                <AccordionContent>
                  Declara <code>--mi-color</code> en ambos modos y regístralo en{" "}
                  <code>@theme inline</code> como <code>--color-mi-color</code>.
                </AccordionContent>
              </AccordionItem>
            </Accordion>

            <div className="space-y-3">
              <h3 className="text-sm font-semibold text-muted-foreground">Timeline de actividad</h3>
              <ol className="relative space-y-4 border-l border-border pl-5">
                {[
                  { title: "Registro creado", time: "hace 5 min" },
                  { title: "Plan actualizado a Pro", time: "hace 2 h" },
                  { title: "Invitación enviada", time: "ayer" },
                ].map((item) => (
                  <li key={item.title} className="relative">
                    <span className="absolute -left-[27px] top-1.5 size-3 rounded-full bg-primary" />
                    <p className="text-sm font-medium">{item.title}</p>
                    <p className="text-xs text-muted-foreground">{item.time}</p>
                  </li>
                ))}
              </ol>
            </div>

            <div className="rounded-2xl border border-dashed border-border p-8 text-center">
              <p className="font-display text-lg font-semibold">Estado vacío</p>
              <p className="mt-1 text-sm text-muted-foreground">
                Aún no hay elementos. Crea el primero para empezar.
              </p>
              <Button className="mt-4">Crear elemento</Button>
            </div>
          </CardContent>
        </Card>
      </div>
    </DashboardLayout>
  );
}
