# Estado actual de la construcción

> Actualizado: 2026-10-12. Lo que existe y funciona, verificado con `composer quality` (API) y `pnpm quality` (web).

## Cómo ejecutar

```bash
# API
cd apps/api && composer install && cp .env.example .env && php artisan key:generate
php artisan migrate:fresh --seed     # usuario de desarrollo: dev@caserito.test
php artisan serve                    # http://127.0.0.1:8000

# Web
cd apps/web && pnpm install && pnpm dev   # http://127.0.0.1:5173 (proxy /api)
```

## Módulos de la API (`apps/api/app/Modules`)

| Módulo | Contenido |
|---|---|
| `Platform` | Cuentas (tenants), usuarios, login por token, perfiles y permisos (RBAC con `deny` prioritario), empresas (RUC validado), sucursales, almacenes |
| `Catalog` | Categorías, marcas, productos con variante predeterminada y código de barras, clientes (DNI/RUC validados) |
| `Inventory` | Libro de movimientos de solo anexado, saldos, costo promedio, reservas, saldo inicial y ajustes, kardex |
| `Fiscal` | Notas de crédito (`CreditNoteService`: por ítems o por monto, con reingreso de stock opcional), baja de comprobantes (`FiscalVoidService`: factura con comunicación de baja, boleta con resumen de anulación; anula la venta al aceptarse), guías de remisión remitente (`DispatchGuideService`), envío/consulta genérico `FiscalRecordSync` y trabajo `SendFiscalRecord`. Base: `EfactCompatibleCalculator` (importes como eFact, pruebas de oro), `FiscalEngine` + `GaviEfactClient` (idempotente por `external_id`, reintentos con espera creciente, errores reintentables o definitivos), configuración cifrada por empresa, comprobantes con estado SUNAT, webhook firmado con deduplicación |
| `Reporting` | Panel de inicio (`GET /dashboard`): ventas del día, más vendidos, por cobrar/pagar, pedidos y envíos abiertos, según permisos |
| `Cash` | Cajas por sucursal, turnos (uno abierto por caja y por usuario), movimientos de caja chica, arqueo con diferencia; exige turno para cobrar en efectivo si la caja lo requiere |
| `Purchasing` | Compras con ingreso de stock a costo (contado o crédito), pagos parciales, anulación sin pagos, cuentas por pagar; proveedores como terceros con `is_supplier` |
| `Shipping` | Agencias de transporte, envíos (ligados o no a un pedido) con estados, línea de tiempo, flete y cobro en destino; rótulo imprimible |
| `Sales` | Ventas (nota de venta) con numeración, contado/crédito, anulación con devolución de stock, cuentas por cobrar y cobros parciales, pedidos con reserva, anticipos y entregas parciales |

## Infraestructura transversal (`app/Shared`)

- **Aislamiento por cuenta:** `TenantContext` + RLS de PostgreSQL forzado en todas las tablas de negocio.
- **Auditoría:** trait `HasAudit` (autor, versión) y `core.audit_log` inmutable (trigger).
- **Ciclo de vida:** `LifecycleService`, `DeletionGuard`, `TableDependencyGuard`, `RecordInUseException` → HTTP 409 con `can_deactivate`.
- **Idempotencia:** middleware `Idempotent` con `core.idempotency_keys`.
- **Maestros:** `ManagedResourceController` y `ManagedRoutes` (listado, alta, edición, activar, eliminar).
- **Decimales:** `Decimal::of()` y bcmath; nunca `float` para importes.

## Rutas principales (`/api/v1`)

| Área | Rutas |
|---|---|
| Sesión | `POST auth/login`, `GET auth/me`, `POST auth/logout` |
| Seguridad | `GET permissions`, CRUD `roles` |
| Usuarios | `GET/POST users`, `PUT users/{id}`, `PATCH users/{id}/active` (propietario protegido, desactivar cierra sesiones) |
| Estructura | `companies`, `branches`, `warehouses` (maestros estándar) |
| Catálogo | `categories`, `brands`, `items` (+ `items/lookup?code=`), `customers` |
| Inventario | `inventory/stock`, `inventory/movements`, `POST inventory/adjustments` |
| Ventas | `sales`, `POST sales/{id}/void`, `POST sales/{id}/payments`, `receivables` |
| Caja | `cash-registers`, `cash-sessions`, `cash-sessions/current`, `POST cash-sessions/{id}/movements`, `POST cash-sessions/{id}/close` |
| Compras | `suppliers`, `purchases`, `POST purchases/{id}/payments`, `POST purchases/{id}/void`, `payables` |
| Envíos | `carriers`, `shipments`, `POST shipments/{id}/status` |
| Facturación | `PUT/GET fiscal/settings/{company}`, `POST sales/{id}/emit`, `GET fiscal/documents`, `POST fiscal/documents/{id}/retry`, `POST fiscal/documents/{id}/void` (baja), `POST fiscal/documents/{id}/credit-notes`, `GET fiscal/credit-notes`, `POST fiscal/credit-notes/{id}/retry`, `GET|POST fiscal/dispatch-guides`, `POST fiscal/dispatch-guides/{id}/retry`, `POST webhooks/efact/{company}` (público, firmado) |
| Panel | `GET dashboard` |
| Cotizaciones | `GET/POST quotations`, `GET quotations/{id}`, `POST quotations/{id}/reject`, `POST quotations/{id}/convert` (convertir crea el pedido y reserva stock) |
| Importación | `POST import/items` (filas leídas del Excel en el navegador; `dry_run` valida sin escribir; categorías/marcas por nombre; stock inicial como apertura) |
| Gastos | `GET/POST expenses`, `POST expenses/{id}/void` (efectivo descuenta del turno abierto; total por rubro) |
| Reportes | `GET reports/sales` (por día, por medio, margen, gastos, resultado estimado), `GET reports/products` |
| Variantes | `POST items/{id}/variants`, `POST items/{id}/variants/generate` (talla × color, reutiliza la predeterminada), `PUT variants/{id}`, `PATCH variants/{id}/active`; POS, pedidos, cotizaciones, compras y stock listan una entrada por variante |
| Listas de precios | CRUD `price-lists`, `GET/PUT price-lists/{id}/items` (escalas por cantidad), `POST prices/resolve`; el cliente tiene `price_list_id` y la venta de mostrador usa la lista si no se envía precio |
| Auditoría | `GET audit-log` (filtros por tipo, usuario y fechas; solo lectura) |
| Devoluciones | `GET/POST sales/{id}/returns` (parciales; reingresan stock; efectivo sale del turno; solo ventas cobradas y sin comprobante vigente) |
| Notificaciones | `GET/PUT notifications/settings`, `GET notifications`, `POST notifications/test`, `POST sales/{id}/notify`, `POST receivables/{id}/notify`, `POST orders/{id}/notify` (WhatsApp vía Evolution API, solo clientes con consentimiento, cola con reintentos) |
| Acceso | `POST auth/forgot-password`, `POST auth/reset-password` (enlace por correo, vale 1 h, cierra sesiones); pantallas `/olvide-mi-clave` y `/restablecer` |
| Fiscal (tarea) | `php artisan fiscal:reconcile` cada 10 min: reenvía o consulta comprobantes sin estado final (requiere `schedule:work` o cron) |
| Seguimiento público | `GET public/tracking/{token}` (pedido o envío, sin ingreso, datos mínimos); pantalla `/seguimiento/{token}`; el aviso de pedido por WhatsApp incluye el enlace |
| Traslados y toma física | `GET/POST inventory/transfers` (salen al costo promedio y entran con ese costo), `POST inventory/counts` (ajusta solo diferencias; deja movimientos `adjustment_*` con documento de toma) |
| Devolución a proveedor | `POST purchases/{id}/returns` (saca stock al costo de la compra y reduce su total; si ya estaba pagada deja saldo negativo = a favor) |
| Plan y límites | `core.tenants.plan_code/max_users/max_companies/max_documents_month` (nulo = sin tope); `GET plan` con uso; crear usuario/empresa sobre el tope responde 403 `plan_limit_reached`. Tope de documentos por mes aún solo informativo |
| Cobros y pagos | `GET collections` y `GET payments` (filtros from/to/method, total del rango); pantallas Cobros y Pagos |
| Atributos | `cat.attributes` (nombre + valores sugeridos); el diálogo de variantes los ofrece y precarga valores |
| Recordatorio automático de deudas | `notify:debts` (diario 09:00): avisa por WhatsApp saldos que vencen en `--days` (2) o ya vencidos, solo con consentimiento, sin repetir el mismo documento antes de `--repeat` (7) días |
| Cobro a varias deudas | `POST receivables/collect-party`: un solo cobro del cliente se reparte entre sus documentos, el que vence primero, un `document_payments` por documento; checkbox en Por cobrar |
| Unir clientes | `POST customers/{id}/merge {into_id}`: mueve ventas, pedidos, cotizaciones, envíos, mensajes, compras y gastos al cliente que se conserva; el duplicado queda `deleted` |
| Edición de pedido | `PUT orders/{id}`: reemplaza líneas, tipo de entrega, fecha y notas mientras no haya entregas; libera y vuelve a reservar; no deja el total bajo el anticipo. Pantalla `/ventas/pedidos/nuevo?edit={id}` |
| Devolución de anticipos | `POST orders/{id}/refund` (solo pedidos cancelados; total o parcial; efectivo sale como egreso del turno abierto); `sales.order_refunds` append-only |
| Cola de armado | `GET orders?status=confirmed,in_dispatch&with_lines=1`; pantalla Por armar ordena por fecha solicitada y avanza el estado. Sin asignación a armador ni ubicaciones todavía |
| Estado de cuenta | `GET customers/{id}/statement`: ventas y cobros en orden con saldo corrido; diálogo con impresión en Clientes |
| Marca blanca | `GET/PUT branding` (nombre comercial, color #RRGGBB, lema, correo, teléfono, pie, «Con tecnología de…»), `GET brand` (marca para cualquier usuario), `POST|DELETE branding/assets/{logo\|logo_dark\|icon}` (PNG/JPG/WebP ≤ 2 MB, validación de medidas; el logo principal se envía a eFact con `POST empresa/logo` y `POST branding/logo/sync` lo reenvía), `PUT branding/domain` + `POST branding/domain/verify` (dominio propio verificado por TXT `_caserito.{dominio}`), públicos `GET public/brand?domain=` y `GET public/brand/{tenant}/{variant}`. El panel aplica color (con contraste AA calculado, claro/oscuro), logo, nombre, favicon y título; login, seguimiento y ticket usan la marca. Archivos en disco `local` bajo `brand/{tenant}`. Sin plantillas de PDF propias: los impresos son de navegador |
| Exportar y valorizar | CSV (con BOM para Excel) desde Cobros, Pagos y Ventas por producto (`lib/csv.ts`); Stock muestra valor a costo promedio |
| Panel SaaS (proveedor) | Operadores en `core.operators` (fuera de toda cuenta), login propio `POST v1/operator/auth/login` con throttle y tokens que no sirven en rutas de cuentas (ni al revés). `GET/POST operator/tenants`, `GET operator/tenants/{id}`, `PUT operator/tenants/{id}/plan` (plan, estado, fin de prueba, topes), con bitácora inmutable. Cuenta `suspended`/`closed`: 403 `account_suspended` en login y API. Pantallas `/operador/login` y `/operador`. Crear operador: `php artisan operator:create correo nombre`; en desarrollo lo crea el seeder |
| Pedidos | `orders`, `orders/{id}/advance`, `orders/{id}/deliver`, `orders/{id}/cancel` |

## Pantallas web (`apps/web/src/routes`)

Inicio, Empresas, Sucursales y almacenes, Productos, Categorías, Marcas, Clientes, Stock, POS, Ventas (ticket 80 mm y anulación), Cotizaciones (lista, alta, conversión en pedido), Pedidos (lista, alta, entrega, cancelación), Importar productos desde Excel, Kardex, Traslados, Toma física, Gastos, Reportes, Variantes (en Productos), Etiquetas con código de barras (impresión), Listas de precios, Auditoría, Notificaciones (WhatsApp), Devolver (en Ventas), ventas en espera en el POS (en el navegador), Por cobrar, Turno de caja, Proveedores, Compras (lista, alta, devolución), Por pagar, Agencias y Envíos (con rótulo), Comprobantes electrónicos, Configuración de facturación, Usuarios, Perfiles (matriz de permisos), y las páginas de desarrollo (catálogo de componentes y contrato de interacción).

## Pendiente próximo

Notas de crédito y baja fiscales, portal del cliente con cuenta (estado de cuenta, historial), avisos automáticos (recordatorios por vencer), planes y límites, ajustes de empresa, y catálogo de atributos (hoy los atributos son pares nombre-valor por variante).

## Notas de entorno

- Desarrollo: PostgreSQL 17 local (`caserito`, usuario `caserito_owner`) y Redis en Docker.
- `search_path` de la conexión: `public,core,cat,inv,sales,cash,purch,ship,fiscal` (para que `migrate:fresh` limpie todos los esquemas).
- Zona horaria de la aplicación: `America/Lima` (los "días" de ventas, vencimientos y reportes se cuentan en hora de Perú).
- Sin repositorio remoto (decisión del usuario). Hay commits locales en `main`. CI lista en `.github/workflows/ci.yml` (API con PostgreSQL sin superusuario + web), sin ejecutar aún.

## Pendientes de verificar contra eFact real

- **Notas de crédito, baja y guías** se implementaron leyendo el código y la documentación de `plataform-api-sunat` (`06-Notas-credito`, `08-Resumen-diario`, `09-Anular`, `10-Guia-remision-RM`), sin probar contra beta. Verificar en beta:
  - Series de notas: `FC01` (facturas) y `BC01` (boletas) deben existir en eFact; la guía usa `T001`.
  - La baja de boleta va por `POST /resumenes` con `anular[{id}]` (el `id` es el de eFact) y se consulta en `/resumenes/{id}/estado`; la de factura por `POST /anulaciones` y `/anulaciones/{id}/estado`.
  - Las guías no admiten `external_id` hoy (pendiente en eFact): un reintento tras un corte entre eFact y nosotros podría duplicar la guía. Riesgo conocido: pedir `external_id` en guías al equipo de eFact.
  - Las guías requieren el plan con `guias_remision` y credenciales GRE configuradas en eFact (si no, 403).
  - Webhook: se ubica el documento por `document.tipo_documento` (`07` nota, `09` guía); los eventos `voided.*` y `summary.*` no se leen, la baja se consulta por el reconciliador.

- Forma exacta de `descuentos` por ítem y de `cuotas` para ventas a crédito (la documentación local no las muestra completas).
- Host de producción (`apifact.gavi-cloud.com` según OpenAPI vs. `apisunatv2.kodevo.es` en `CONTEXT.md`).
- Probar emisión end-to-end en entorno beta antes de activar producción.

### Marca blanca: qué falta para producción
- **Dominio propio**: la verificación prueba propiedad por DNS, pero el servidor web debe atender ese nombre (CNAME/A al host del front y proxy de `/api` hacia la API) y tener su certificado TLS (p. ej. Caddy con TLS bajo demanda). Sin eso, el dominio verificado no abre la aplicación.
- **Límite por plan**: el plan `white_label` aún no condiciona logos ni dominio (no existe tabla de features); hoy cualquier cuenta puede usarlos.
- **Logo en eFact**: el contrato `POST /empresa/logo` (campo `logo`, multipart) se tomó del código de `plataform-api-sunat`; verificar en beta.
- **Plantillas de PDF**: no hay generador propio; los tickets y el estado de cuenta se imprimen desde el navegador con el logo, teléfono y pie de la marca.
