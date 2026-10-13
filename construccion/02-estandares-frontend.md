# Estándares de frontend (UI/UX y componentes)

Fecha: 2026-10-12. Estado: acordado en lo esencial. Complementa `01-estandares-de-codigo.md` y `../diseno/05-sistema-visual.md`.
Base visual: `D:\Proyectos React - Next JS\aura-admin-theme` (se copia a `apps/web`; ver §2).

## 1. Principios
1. **Reutilizar antes de crear:** el tema ya trae componentes y tokens bien definidos; no se reescribe lo que existe.
2. **Un solo lugar para cada decisión visual:** colores, radios, sombras y tipografía viven en tokens, no en componentes.
3. **Componentes generalizados:** cada patrón (botón, selector, formulario, tabla, diálogo) se define una vez y se usa en toda la aplicación.
4. **UI/UX deliberada:** jerarquía, densidad, estados y accesibilidad se diseñan, no se improvisan.
5. **Marca blanca desde el inicio:** la paleta cambia por empresa sin tocar componentes.

## 2. Qué se toma del tema y qué se adapta
| Elemento del tema | Uso en Gavi Caserito |
|---|---|
| `src/styles.css` (tokens en `oklch`, modo claro y oscuro, `@theme inline`) | Fuente única de la paleta. Se cambia solo el tono (H) para la marca; la paleta por empresa se aplica sobrescribiendo variables CSS |
| `components/ui/*` (Radix + shadcn, ~50 componentes) | Base del kit. Se conservan; se envuelven en componentes de dominio cuando hace falta |
| `components/ui/sidebar.tsx` y `components/dashboard/*` | Estructura de la aplicación; el menú se rehace con **grupos desplegables** (ver §5) |
| `components/data-table/advanced-table.tsx` | Base de las tablas; se adapta a paginación, filtros y orden **del servidor** |
| `components/ui/form.tsx`, `combobox.tsx`, `input-group.tsx` | Base de formularios y selectores (react-hook-form + zod) |
| `routes/design-system.tsx` | Se conserva como **catálogo vivo** de componentes (ruta solo en desarrollo) |
| Rutas y datos de demostración (`index.tsx`, `tabla.tsx`, `src/data/records.ts`) | Se eliminan o reemplazan |
| TanStack **Start** (SSR), `server.ts`, `start.ts`, integración Lovable | Se retiran: la aplicación es una SPA tras inicio de sesión |
| Radio `0.875rem` y tono ámbar de `--primary` | Se ajustan: radio ~0.5rem (compacto) y tono corporativo definido por la marca |

## 3. Tokens y paleta
- Todos los colores son tokens CSS (`--primary`, `--success`, `--warning`, `--destructive`, `--info`, `--sidebar-*`…). **Prohibido** usar colores literales en componentes.
- Estados semánticos fijos: `success` (éxito), `warning` (advertencia), `destructive` (peligro), `info`. Coinciden con los avisos de `05-sistema-visual.md`.
- **Paleta por empresa (marca blanca):** la empresa define un color principal; el sistema deriva el resto cambiando el tono (H) y verifica contraste AA. Se aplica con un atributo `data-brand` o variables en `:root` al cargar la cuenta.
- Modo claro y oscuro con las mismas variables; el modo se guarda por usuario.
- Tipografía y espaciado de la escala del tema; densidad compacta como valor por defecto (opción "cómodo").

## 4. Componentes generalizados
### 4.1 Capas
| Capa | Carpeta | Qué contiene | Regla |
|---|---|---|---|
| Primitivos | `components/ui` | Los del tema (button, input, select, dialog, sheet, tabs…) | Sin lógica de negocio; solo estilo y accesibilidad |
| Compuestos | `components/common` | `FormDialog`, `ConfirmDialog`, `ActionButton`, `FormField`, `DataTable`, `EmptyState`, `ErrorState`, `PageHeader`, `StatusChip`, `MoneyInput`, `QuantityInput`, `BarcodeInput`, `PartyPicker`, `ItemPicker`, `DocumentTotals` | Genéricos, sin dependencias de módulos |
| De dominio | `modules/<modulo>/components` | `OrderTimeline`, `ShipmentForm`, `PaymentPanel`, `PosTicket`… | Usan compuestos y servicios del módulo |
| Páginas | `modules/<modulo>/pages` | Composición de pantallas | Sin estilo propio; solo composición |

### 4.2 Compuestos obligatorios y su contrato
| Componente | Responsabilidad |
|---|---|
| `ActionButton` | Estados `idle`, `processing` (con verbo "Registrando…"), `disabled`; evita doble clic |
| `FormDialog` | Modal de formulario con bloqueo de contexto, cierre inhabilitado mientras corre la petición y mapeo de errores a campos |
| `ConfirmDialog` | Confirmaciones destructivas con el nombre del elemento y el mismo bloqueo |
| `useGuardedMutation` | Aplica bloqueo, llave de idempotencia, tiempo máximo (15 s aviso, 30 s corte) y diccionario de errores |
| `DataTable` | Skeleton, estado vacío y de error, orden y filtros del servidor, paginación por cursor, acciones de fila |
| `FormField` | Etiqueta, ayuda, error y ARIA coherentes; integra react-hook-form |
| `StatusChip` | Colores por estado de dominio desde un único mapa |
| `MoneyInput` | Importes en PEN/USD con decimales correctos; nunca `float` en la lógica |
| `BarcodeInput` | Campo con foco permanente para lector USB o cámara |

### 4.3 Reglas
- Un componente nuevo nace en `components/common` si lo usan **dos o más** módulos; antes vive en su módulo.
- Cada componente: tipos exportados, **documentación TSDoc en español**, historia/ejemplo en el catálogo, prueba de comportamiento y revisión de accesibilidad.
- Las propiedades son pequeñas y estables; variantes por `cva` (class-variance-authority), como ya hace el tema.
- Ningún componente decide textos: reciben claves o textos ya traducidos.

## 5. Navegación (menú lateral)
- Menú **por categorías con desplegables** (ver `../diseno/04-flujos-y-pantallas.md` §1): Comercialización, Despacho y envíos, Cobranzas y pagos, Compras, Inventario, Catálogo, Terceros, Caja, Facturación, Configuración; Inicio y Reportes como elementos directos.
- Implementación con `SidebarGroup` + `Collapsible` + `SidebarMenuSub` del kit.
- Reglas:
  - El grupo con la ruta activa se abre solo; el estado de apertura se recuerda por usuario.
  - Un grupo sin ítems visibles (por permiso, plan o configuración) no se muestra.
  - Un ítem de un módulo no incluido en el plan aparece con candado.
  - Colapsado a iconos en tablet; en móvil, menú en panel lateral.
  - Insignias para pendientes (por ejemplo, rechazados) donde aporten.
- El menú sale de **una configuración declarativa** con permiso, icono y ruta; no se escribe JSX por ítem.

## 6. UI/UX: cómo se trabaja
### 6.1 Herramientas de apoyo
| Herramienta | Cuándo se usa |
|---|---|
| Skill **ui-ux-pro-max** | Antes de diseñar o rediseñar una pantalla o componente: estilo, jerarquía, paletas, tipografía, accesibilidad |
| Skill **impeccable** | Para criticar, pulir y endurecer una interfaz terminada: auditoría, estados límite, textos, rendimiento, responsividad |
| `design-system.tsx` | Referencia viva de lo que ya existe antes de crear algo nuevo |

Se aplican **solo cuando aportan**: pantallas de alto impacto (POS, pedido, producto, panel de inicio) y revisiones previas a cada hito. No se usan para cada formulario simple.

### 6.2 Proceso por pantalla
1. Leer el flujo en `04-flujos-y-pantallas.md` y el wireframe.
2. Identificar componentes existentes y los que faltan.
3. Construir con datos de ejemplo y los cuatro estados: cargando, vacío, error, éxito.
4. Revisar accesibilidad, teclado y responsividad.
5. Auditoría de calidad con la skill correspondiente en pantallas críticas.
6. Registrar en el catálogo vivo lo nuevo.

### 6.3 Criterios de aceptación UI
- Contraste AA, foco visible y orden de tabulación lógico.
- Operable con teclado en POS y formularios.
- Sin saltos de diseño al cargar (esqueletos con la forma real).
- Responsivo desde 360 px de ancho.
- Textos en español claro, sin jerga técnica ni códigos de SUNAT en la vista básica.
- Estados `processing`, `error` y `success` implementados con los compuestos del contrato.

## 7. Buenas prácticas de código frontend
- TypeScript estricto; sin `any`.
- Estructura por módulos, igual que la API: `modules/<modulo>/{pages,components,hooks,api,types}`.
- Datos del servidor con TanStack Query; claves de consulta centralizadas por módulo.
- Formularios con react-hook-form + zod; los códigos de error de la API se mapean a campos.
- Rutas con TanStack Router (basadas en archivos), carga perezosa por módulo; POS en paquete aparte.
- Textos en archivos de traducción (`es-PE`), con claves en inglés.
- Sin estilos en línea ni valores mágicos: tokens y utilidades.
- Pruebas: componentes con Testing Library; flujos críticos con Playwright.
- Linter con `jsdoc` obligatorio, ESLint y Prettier en CI.

## 8. Rendimiento
- Presupuesto inicial: la ruta de POS < 250 KB comprimidos de JavaScript.
- Tablas virtualizadas desde 200 filas.
- Imágenes de producto con tamaños derivados y carga diferida; el POS muestra imagen (decisión de la revisión).
- Caché de catálogo local acotada para el POS.
