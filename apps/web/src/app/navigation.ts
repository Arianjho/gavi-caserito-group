import {
  Boxes,
  Banknote,
  BookUser,
  Landmark,
  LayoutDashboard,
  LineChart,
  PackageSearch,
  Receipt,
  Settings,
  ShoppingBag,
  ShoppingCart,
  Tags,
  Truck,
  type LucideIcon,
} from "lucide-react";

/** Elemento de navegación que lleva a una pantalla. */
export interface NavLeaf {
  /** Texto visible. */
  title: string;
  /** Ruta de la pantalla; si falta, el ítem se muestra como "próximamente". */
  to?: string;
  /** Permiso requerido (`modulo.recurso.accion`); si falta, es visible para todos. */
  permission?: string;
  /** Insignia opcional (por ejemplo, un contador de pendientes). */
  badge?: string;
}

/** Elemento de primer nivel: ítem directo o grupo desplegable. */
export type NavEntry =
  | ({ kind: "item"; icon: LucideIcon } & NavLeaf)
  | { kind: "group"; id: string; title: string; icon: LucideIcon; items: NavLeaf[] };

/**
 * Menú lateral de la aplicación.
 *
 * Es **declarativo**: el componente de menú no contiene JSX por ítem. Los ítems
 * sin `to` aún no tienen pantalla y se muestran deshabilitados.
 * La visibilidad final depende de los permisos del usuario y del plan.
 */
export const navigation: NavEntry[] = [
  { kind: "item", icon: LayoutDashboard, title: "Inicio", to: "/" },
  {
    kind: "group",
    id: "commerce",
    title: "Comercialización",
    icon: ShoppingCart,
    items: [
      { title: "POS", to: "/ventas/pos", permission: "sales.pos.use" },
      { title: "Cotizaciones", to: "/ventas/cotizaciones", permission: "orders.quotation.view" },
      { title: "Pedidos", to: "/ventas/pedidos", permission: "orders.order.view" },
      { title: "Ventas", to: "/ventas", permission: "sales.document.view" },
      { title: "Devoluciones y notas", permission: "sales.note.view" },
    ],
  },
  {
    kind: "group",
    id: "shipping",
    title: "Despacho y envíos",
    icon: Truck,
    items: [
      { title: "Por armar", to: "/envios/por-armar", permission: "orders.order.view" },
      { title: "Envíos", to: "/envios", permission: "shipping.shipment.view" },
      { title: "Agencias", to: "/envios/agencias", permission: "shipping.carrier.view" },
    ],
  },
  {
    kind: "group",
    id: "collections",
    title: "Cobranzas y pagos",
    icon: Banknote,
    items: [
      { title: "Por cobrar", to: "/cobranzas/por-cobrar", permission: "payments.receivable.view" },
      { title: "Cobros", to: "/cobranzas/cobros", permission: "payments.collection.view" },
      { title: "Por pagar", to: "/pagos/por-pagar", permission: "payments.payable.view" },
      { title: "Pagos", to: "/pagos/pagos", permission: "payments.payment.view" },
    ],
  },
  {
    kind: "group",
    id: "purchases",
    title: "Compras",
    icon: ShoppingBag,
    items: [
      { title: "Compras", to: "/compras", permission: "purchases.document.view" },
      { title: "Gastos", to: "/compras/gastos", permission: "purchases.expense.view" },
    ],
  },
  {
    kind: "group",
    id: "inventory",
    title: "Inventario",
    icon: Boxes,
    items: [
      { title: "Stock", to: "/inventario/stock", permission: "inventory.stock.view" },
      { title: "Kardex", to: "/inventario/kardex", permission: "inventory.movement.view" },
      { title: "Ajustes", permission: "inventory.adjustment.view" },
      { title: "Traslados", to: "/inventario/traslados", permission: "inventory.transfer.view" },
      { title: "Toma física", to: "/inventario/toma-fisica", permission: "inventory.count.create" },
    ],
  },
  {
    kind: "group",
    id: "catalog",
    title: "Catálogo",
    icon: Tags,
    items: [
      { title: "Productos", to: "/catalogo/productos", permission: "catalog.item.view" },
      { title: "Categorías", to: "/catalogo/categorias", permission: "catalog.category.view" },
      { title: "Marcas", to: "/catalogo/marcas", permission: "catalog.brand.view" },
      { title: "Atributos", to: "/catalogo/atributos", permission: "catalog.attribute.view" },
      {
        title: "Listas de precios",
        to: "/catalogo/listas-precios",
        permission: "catalog.price.view",
      },
      { title: "Etiquetas", to: "/catalogo/etiquetas", permission: "catalog.label.print" },
    ],
  },
  {
    kind: "group",
    id: "parties",
    title: "Terceros",
    icon: BookUser,
    items: [
      { title: "Clientes", to: "/terceros/clientes", permission: "parties.customer.view" },
      { title: "Proveedores", to: "/terceros/proveedores", permission: "parties.supplier.view" },
    ],
  },
  {
    kind: "group",
    id: "cash",
    title: "Caja",
    icon: Landmark,
    items: [
      { title: "Turnos", to: "/caja/turno", permission: "cash.session.view" },
      { title: "Movimientos", permission: "cash.movement.view" },
    ],
  },
  {
    kind: "group",
    id: "invoicing",
    title: "Facturación",
    icon: Receipt,
    items: [
      {
        title: "Comprobantes",
        to: "/facturacion/comprobantes",
        permission: "fiscal.document.view",
      },
      {
        title: "Notas de crédito",
        to: "/facturacion/notas",
        permission: "fiscal.document.view",
      },
      { title: "Guías", to: "/facturacion/guias", permission: "fiscal.dispatch.view" },
      { title: "Pendientes y rechazados", permission: "fiscal.queue.view" },
      { title: "Series", permission: "fiscal.series.view" },
    ],
  },
  {
    kind: "item",
    icon: LineChart,
    title: "Reportes",
    to: "/reportes",
    permission: "reports.view",
  },
  {
    kind: "group",
    id: "settings",
    title: "Configuración",
    icon: Settings,
    items: [
      { title: "Empresas", to: "/configuracion/empresas", permission: "settings.company.view" },
      {
        title: "Sucursales y almacenes",
        to: "/configuracion/sucursales",
        permission: "settings.branch.view",
      },
      { title: "Usuarios", to: "/configuracion/usuarios", permission: "settings.user.view" },
      { title: "Perfiles", to: "/configuracion/perfiles", permission: "settings.role.view" },
      { title: "Medios de pago", permission: "settings.payment.view" },
      { title: "Marca", to: "/configuracion/marca", permission: "settings.template.view" },
      {
        title: "Facturación electrónica",
        to: "/configuracion/facturacion",
        permission: "settings.company.update",
      },
      { title: "Impresión", permission: "settings.printing.update" },
      {
        title: "Notificaciones",
        to: "/configuracion/notificaciones",
        permission: "settings.notification.view",
      },
      { title: "Importar", to: "/configuracion/importar", permission: "import.run" },
      { title: "Auditoría", to: "/configuracion/auditoria", permission: "audit.log.view" },
      { title: "Mi plan", to: "/configuracion/plan" },
    ],
  },
  {
    kind: "group",
    id: "dev",
    title: "Desarrollo",
    icon: PackageSearch,
    items: [
      { title: "Catálogo de componentes", to: "/design-system" },
      { title: "Contrato de interacción", to: "/dev/interaccion" },
    ],
  },
];
