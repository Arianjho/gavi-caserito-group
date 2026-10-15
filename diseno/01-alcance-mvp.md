# Alcance del MVP (v1)

Fecha: 2026-10-11. Estado: borrador. Decisiones que lo sustentan: `00-decisiones-base.md` (D1–D12).

Leyenda: **Núcleo** = sin esto no hay producto · **Incluido** = entra en v1 · **Después** = v1.5/v2.

## 1. Plataforma SaaS (multi-tenant)
| Capacidad | Estado | Notas |
|---|---|---|
| Alta de negocio (tenant → empresa → sucursal → almacén) | Núcleo | Asistente de arranque |
| Usuarios, roles y permisos por perfil, menú y acción | Núcleo | Ver §9; complejo desde v1 |
| Auditoría (quién, qué, cuándo, antes/después) | Núcleo | Acciones sensibles y cambios de precio/stock |
| Cuenta con **varias empresas (RUC)**, sucursales y almacenes | Núcleo | D16; el plan limita nº de empresas |
| Marca blanca: logo, color y plantillas de PDF por empresa | Núcleo | D18 |
| Planes y límites (empresas, usuarios, sucursales, ítems, emisión) | Núcleo | Ver §10 |
| Panel del proveedor (super-admin): tenants, plan, cobro manual, suspensión, uso de cupo SUNAT | Núcleo | Los cobros los registra el proveedor a mano |
| Notificaciones WhatsApp (bot del sistema) | Incluido | Ver §8 |
| Pasarela de pago / cobro automático | Después | |

## 2. Maestros
| Capacidad | Estado | Notas |
|---|---|---|
| Terceros (cliente, proveedor, ambos) con DNI/RUC y búsqueda automática vía eFact | Núcleo | Un solo maestro |
| Ítems: tipo bien/servicio, UM Cat. 03 (NIU/ZZ por defecto), afectación Cat. 07 con default por empresa | Núcleo | |
| Categorías, marcas, listas de precios simples | Incluido | |
| Varios códigos de barras por ítem; generación de códigos internos; **impresión de etiquetas** | Núcleo | Etiquetas en v1 (decidido) |
| Conversión de unidades (caja → unidad) | Incluido | |
| **Atributos y variantes** (talla, color, tamaño u otros definidos por el negocio); ítem padre + variantes con código de barras, precio y stock propios | Núcleo | Multi-rubro: el negocio define sus atributos; plantillas por rubro |
| Empaques y presentaciones (unidad, docena, ciento, caja) y precios por escala de cantidad / por cliente | Núcleo | |
| Series/IMEI, lotes y vencimiento | Después | Diseñar el modelo para admitirlos |
| Combos/kits | Después | |

## 3. Inventario (configurable)
| Capacidad | Estado | Notas |
|---|---|---|
| `controla_stock` por empresa e ítem; política de stock negativo | Núcleo | La mayoría lo usa |
| Kardex, stock por almacén, ajustes, traslados | Núcleo | |
| Inventario físico (toma de inventario) | Incluido | |
| Alertas de stock mínimo | Incluido | Aviso por WhatsApp opcional |

## 4. Ventas y POS
| Capacidad | Estado | Notas |
|---|---|---|
| POS: pantalla propia, lector de barras, búsqueda, atajos, ventas en espera | Núcleo | Tablet/celular y PC |
| Venta **contado** y **crédito** (cuotas y vencimiento) | Núcleo | Crédito genera cuenta por cobrar |
| Pago mixto (varios medios en una venta) | Núcleo | Efectivo, Yape, Plin, transferencia, tarjeta, otros |
| Descuentos por ítem y globales con permiso | Núcleo | Límite por rol |
| Documentos: nota de venta, boleta, factura; NC/ND | Núcleo | Según modo fiscal |
| Cotizaciones y conversión a venta | Incluido | |
| Devoluciones y anulaciones (máquina de estados) | Núcleo | |
| Ticket 58/80 mm y A4; envío por WhatsApp/enlace | Núcleo | |
| POS sin conexión (venta al contado, cola local) | Después (v1.5) | v1: reintento seguro; D19 |
| Guía de remisión | Incluido (opcional) | Plan con emisión; usa eFact |

## 4b. Pedidos y envíos (núcleo diferenciador)
| Capacidad | Estado | Notas |
|---|---|---|
| **Pedido** como entidad: origen (WhatsApp, mostrador, portal), líneas, estados, entregas parciales | Núcleo | La venta nace del pedido |
| Reserva de stock al confirmar; liberación al cancelar | Núcleo | |
| Anticipos y saldo del pedido (ej. 50 %) | Núcleo | Con medio de pago; se descuenta al facturar |
| Armado (picking): lista de preparación, bultos | Incluido | |
| **Envío** como entidad: agencia/transportista (catálogo), destino, nº de guía de la agencia, clave de recojo, flete y quién lo paga, bultos, foto del cargo | Núcleo | Registro manual en v1 |
| Línea de tiempo de estados del envío (sin GPS) | Núcleo | |
| Cobro en destino y rendición | Incluido | |
| **Portal del cliente** por enlace mágico: pedidos, estados, comprobantes, saldo, repetir pedido | Incluido | Sin contraseña; vía WhatsApp |
| Aviso por WhatsApp en cada cambio de estado | Incluido | Ver §8 |
| Integración automática con agencias (Olva, Cruz del Sur, agregadores) | Después | Puerto `CarrierTracker` |
| Rutas de reparto propias, preventa en campo | Después | |

## 5. Caja (opcional y configurable)
- Modo por empresa/caja: **sin apertura** (movimientos sueltos) o **con apertura/cierre**.
- Arqueo con **conteo por denominación PEN** (billetes y monedas) o **monto final directo** (modo rápido).
- Ingresos/egresos de caja chica, diferencias de cierre, reporte por turno y por cajero.
- Multimoneda en caja (USD): Después.

## 6. Compras y pagos
| Capacidad | Estado |
|---|---|
| Compra **contado** y **crédito**; pago mixto | Núcleo |
| Cuentas por pagar con vencimientos y pagos parciales | Núcleo |
| Cuentas por cobrar con vencimientos, cobros parciales y estado de cuenta | Núcleo |
| Gastos y categorías de gasto | Incluido |
| Orden de compra | Después |
| Registro/validación de compras con SIRE | Después |

## 7. Fiscal (módulo opcional)
| Capacidad | Estado |
|---|---|
| Modo fiscal por empresa: `none` / `optional` / `required` | Núcleo |
| `FiscalEngine` + `GaviEfact`: boleta, factura, NC, ND, baja, resumen | Incluido (con plan de emisión) |
| Cola de emisión, reintentos, webhooks, reconciliador, pantalla de rechazos | Incluido |
| Asistente de activación (certificado digital, clave SOL, series) con modo beta | Incluido |
| Copia propia de XML/CDR/PDF | Incluido |
| Retención/percepción/reversión, SIRE | Después |

## 8. Notificaciones WhatsApp (Evolution API)
Un número del sistema actúa como bot. Tres destinatarios:
1. **Cliente final:** comprobante (PDF/enlace), recordatorio de cuota por vencer, estado de cuenta.
2. **Dueño del negocio:** cierre de caja, stock mínimo, comprobante rechazado, cuentas por vencer.
3. **Proveedor del SaaS (tú):** alta de negocio, pago por vencer, cupo agotado, errores del motor fiscal.

Requisitos: consentimiento (opt-in), límite de envíos, cola con reintentos, plantillas, registro de envíos, canal alterno (correo) y puerto `Notifier` para poder cambiar de proveedor.

## 9. Roles y permisos (complejo desde v1)
- Catálogo de permisos `modulo.recurso.accion` (ver, crear, editar, archivar, anular, exportar, aprobar…).
- **Roles/perfiles por tenant**, con plantillas iniciales (administrador, vendedor, cajero, almacenero, contador).
- **Visibilidad de menú** derivada de permisos.
- **Alcance:** por sucursal, almacén y caja.
- **Límites numéricos:** descuento máximo, monto máximo de anulación, etc.; con aprobación de un supervisor.
- Excepciones por usuario sobre el rol; auditoría de cambios de permisos.
- Verificación siempre en servidor (API), nunca solo ocultando botones.

## 10. Planes (esbozo, sin precios)
| Eje | Cómo se limita |
|---|---|
| Funcional | **empresas**, usuarios, sucursales, almacenes, ítems, módulos (caja, compras, WhatsApp, marca blanca, etc.) |
| Emisión SUNAT | cupo mensual de comprobantes (alineado a 200 / 1,000 / 5,000 del SaaS) |

- Los precios se fijan por margen sobre el costo del SaaS (S/0.15 / 0.059 / 0.026 por comprobante). **Propuesta con análisis de mercado en `investigacion/06-mercado-y-precios.md` (pendiente de aprobar).**
- El proveedor revende la suscripción de eFact dentro del plan.
- Sin plan de emisión: modo fiscal `none`, solo notas de venta.

## 11. Onboarding y migración
| Capacidad | Estado |
|---|---|
| Importación de productos, clientes y proveedores desde Excel/CSV con plantilla, validación previa y reporte de errores | Núcleo |
| Importación de saldos iniciales (stock, cuentas por cobrar y por pagar) | Núcleo |
| Mapeo de columnas de sistemas conocidos (IntiFact y similares) | Incluido |
| Asistente de arranque por rubro (datos básicos, series, caja, impuestos) | Núcleo |
| Datos demo que se pueden borrar de una vez | Incluido |
| Tutoriales dentro de la app (recorrido guiado, ayuda contextual, videos cortos, centro de ayuda) | Incluido |

## 12. Reportes (v1)
Ventas por período, producto, vendedor y medio de pago; stock valorizado; cuentas por cobrar y por pagar con antigüedad; resumen de caja; comprobantes por estado. **Panel de inicio en v1 (decidido):** ventas del día, por cobrar, por pagar, pedidos por estado, envíos pendientes, stock bajo, caja.

## 13. Interfaz
- Tema base: `aura-admin-theme` como kit (shadcn/Radix, Tailwind 4), ajustado a corporativo y compacto.
- Reglas: modal para CRUD simple, panel lateral para formularios medios, página completa para documentos transaccionales, POS en pantalla propia, sin modal sobre modal.
- Mobile first para POS y consulta.

## 14. Fases propuestas
| Fase | Contenido |
|---|---|
| 0 | Plataforma: tenancy, auth, roles/permisos, auditoría, panel proveedor |
| 1 | Maestros: terceros, ítems con atributos y variantes, empaques, precios, importación Excel |
| 2 | Inventario: almacenes, kardex, reservas, ajustes, traslados |
| 3 | Pedidos y ventas: cotización → pedido → venta, contado/crédito, anticipos, pago mixto |
| 4 | POS y caja, tickets |
| 5 | Cobranzas y pagos: CxC, CxP, compras, gastos |
| 6 | Envíos: agencias, guía/clave, estados, cobro en destino |
| 7 | Fiscal: FiscalEngine, cola, webhooks, activación asistida, GRE |
| 8 | Notificaciones WhatsApp, portal del cliente, tutoriales, panel de inicio, reportes |

El orden permite vender y cobrar antes de depender de SUNAT, y el hilo pedido→envío antes que las integraciones externas. Las fases 7 y 8 pueden solaparse.

## 15. Decisiones tomadas y pendientes
**Resueltas (2026-10-11)**
1. Panel de inicio: **v1**.
2. Etiquetas de código de barras: **v1**.
3. Migración prioritaria: **Excel libre** (plantilla propia con mapeo de columnas).
4. Precios de planes y paquetes de emisión: **se definen al final**, con el producto listo.
5. Aprovisionamiento en Gavi eFact: **por API** (`bridge/provision`); plan y pago manual en el panel de eFact en v1.
6. WhatsApp: se usa Evolution API como canal por decisión del producto; el riesgo de bloqueo se mitiga con puerto `Notifier`, opt-in y límites.
7. GRE: se diseña como **paso opcional y configurable** del envío (no bloquea el diseño). Las fechas obligatorias se verifican con SUNAT/contador antes de la Fase 7.
8. Sin entrevistas por ahora: se construye sobre las definiciones actuales y se ajusta con los primeros usuarios.
9. Asistente de IA y MCP: fuera de v1 (D15).

**Pendientes**
- Precios (al final).
- Endpoint de plan/pago en el bridge de eFact (backlog del SaaS).
- Verificación con SUNAT de la obligatoriedad de GRE en traslados a provincias.
