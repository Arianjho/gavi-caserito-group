# Plan de construcción

Fecha: 2026-10-11. Estado: borrador para validar capacidad y orden.
Base: `../diseno/01-alcance-mvp.md` (fases), `02-arquitectura.md`, `03-modelo-de-datos.md`, `04-flujos-y-pantallas.md`, `01-estandares-de-codigo.md`.

## 1. Principios de construcción
1. **Cortes verticales:** cada fase entrega algo que se puede usar de punta a punta (base de datos, servicio, API, pantalla, pruebas, documentación), no capas sueltas.
2. **Esqueleto andante primero:** antes de profundizar, un recorrido mínimo completo (ingresar → crear producto → vender → cobrar → ver stock) en producción de pruebas. Valida arquitectura, despliegue y estándares.
3. **Riesgo temprano:** lo incierto se prueba pronto con *spikes* acotados (eFact, GRE, WhatsApp, PDF, aislamiento de tenants, modo sin conexión).
4. **Los cimientos no se postergan:** permisos, auditoría, aislamiento de tenants, borrado lógico y documentación se construyen desde la Fase 0, no al final.
5. **Se vende antes de depender de SUNAT:** el hilo pedido→venta→cobro funciona sin emisión; la emisión se enchufa por el puerto.
6. **Cada fase termina con criterios de aceptación medibles** y una demostración con datos reales de un rubro.

## 2. Supuestos (a confirmar)
- Equipo muy pequeño (posiblemente una persona) con apoyo de Claude Code. Por eso el plan usa **tamaños relativos** (S, M, L, XL) y no fechas.
- Un repositorio (monorepo) con API Laravel, SPA React y paquetes compartidos.
- Servidor propio separado del VPS de eFact.
- El nombre y dominio del producto aún no están definidos (necesarios antes de la beta).

| Tamaño | Orden de magnitud relativo |
|---|---|
| S | 1 |
| M | 2–3 |
| L | 5–6 |
| XL | 8–10 |

## 3. Fase P — Preparación (antes de la Fase 0)
| # | Tarea | Resultado |
|---|---|---|
| P1 | Repositorio, ramas, CI (formato, estático, pruebas, documentación obligatoria) | Pipeline verde con proyecto vacío |
| P2 | Entornos: local (contenedores), staging, producción en servidor propio | Despliegue automático a staging |
| P3 | Spike **eFact**: aprovisionar en beta, emitir boleta y factura de prueba, recibir webhook | Cliente mínimo y fixtures de oro para `TaxCalculator` |
| P4 | Spike **aislamiento multi-tenant**: `tenant_id` + RLS con Laravel y colas | Decisión técnica documentada (ADR) |
| P5 | Spike **PDF/etiquetas/tickets**: motor (HTML a PDF) con A4, ticket 80/58 y etiquetas | ADR y consumo de memoria medido |
| P6 | Spike **Evolution API**: envío, plantillas, recepción de eventos, límites | Cliente mínimo y reglas de envío |
| P7 | Evaluar `aura-admin-theme`: extraer componentes, tokens compactos, tema corporativo | Kit de UI inicial |
| P8 | Verificar GRE con SUNAT o contador (obligatoriedad y umbral) | Decisión registrada |
| P9 | Definir nombre, dominio y marca | Nombre, dominio, correo de sistema |

Tamaño total: **M–L**. Salida: ADRs resueltos (§20 de `02-arquitectura.md`) y repositorio operativo.

## 4. Fases de construcción

### Fase 0 — Plataforma (L)
**Objetivo:** cuenta, empresas, usuarios y permisos funcionando con aislamiento verificable.
- Autenticación (sesión/token), recuperación, bloqueo por intentos, doble factor opcional.
- Cuenta → empresas → sucursales → almacenes (CRUD con ciclo de vida activo/inactivo/eliminado).
- Roles, permisos, alcance y límites; plantillas de perfil.
- Auditoría estándar (columnas y `audit_log`), idempotencia, eventos de dominio y outbox.
- Planes y límites; panel del proveedor mínimo (cuentas, plan, cobro manual, suspensión).
- Capa de servicios con actor; contrato de errores y diccionario.
- Cascarón de interfaz: navegación por permisos, selector empresa/sucursal, componentes del contrato (`FormDialog`, `ConfirmDialog`, `ActionButton`, `useGuardedMutation`).
- Catálogos SUNAT sembrados (`pe.*`).

**Aceptación:** pruebas de aislamiento de tenants en verde; un usuario con perfil restringido no ve ni ejecuta lo no permitido (API y menú); toda acción sensible queda en auditoría; documentación y `COMMENT` completos.

### Fase 1 — Maestros e importación (L)
**Objetivo:** catálogo y terceros listos, con carga masiva.
- Terceros (alta, búsqueda RUC/DNI por eFact, direcciones, contactos, condiciones por empresa).
- Ítems: bien/servicio, UM Cat. 03 (NIU/ZZ), afectación por defecto, categorías, marcas.
- Atributos y variantes, empaques, códigos de barras, listas de precios con escalas.
- Guardia de eliminación, desactivación y **fusión**.
- Importación Excel con validación en seco, reversión y datos demo por rubro.
- Etiquetas de código de barras.

**Aceptación:** importar 5 000 filas en menos de 5 min con informe de errores; un producto con 12 variantes se crea, se imprime su etiqueta y se busca por código en < 300 ms.

### Fase 2 — Inventario (M–L)
- Movimientos append-only, saldos, reservas, ajustes, traslados, toma física y alertas.
- Saldos iniciales por importación.
- Política de stock negativo; costo promedio.
- Kardex y reportes de stock.

**Aceptación:** reconstruir saldos desde movimientos da el mismo resultado; reservas concurrentes no sobrevenden (prueba de concurrencia).

### Fase 3 — Pedidos y ventas (XL)
- `TaxCalculator` compatible con eFact (pruebas de oro).
- Cotizaciones, pedidos con reservas y anticipos, entregas parciales.
- Documentos de venta: nota de venta (sin emisión), contado/crédito, cuotas, descuentos con límite.
- Numeración y series internas; conversión de nota a comprobante preparada.
- Anulación con contra-movimientos.

**Aceptación:** los totales coinciden con los casos de oro; un pedido pasa de cotización a venta con anticipo y entrega parcial; la anulación revierte stock y pagos.

### Fase 4 — POS y caja (L)
- POS completo (escaneo, ventas en espera, pago mixto, atajos, ticket).
- Caja con o sin turnos; arqueo por denominación o monto final; movimientos.
- Medios de pago y cuentas por empresa.
- PWA instalable base; indicador de conexión; reintento seguro (nivel 0).

**Aceptación:** venta de 3 líneas con pago mixto en < 10 s con lector; cierre de turno con diferencia calculada; ninguna venta duplicada tras un corte simulado.

**Hito M1 — "Vender y cobrar":** Fases 0 a 4 terminadas. Primer uso real posible para negocios sin emisión.

### Fase 5 — Cobranzas, pagos y compras (L)
- Pagos y asignaciones, cobros parciales, saldo a favor, estado de cuenta.
- Cuentas por cobrar y por pagar con antigüedad.
- Compras contado/crédito, gastos y categorías.
- Recordatorios de cuotas (sin envío aún; eventos listos).

**Aceptación:** el saldo de cada cliente = documentos − asignaciones; una compra a crédito aparece en Por pagar con su vencimiento.

### Fase 6 — Envíos (L)
- Agencias y oficinas, armado (picking), bultos.
- Envíos con guía/clave, flete y quién lo paga, cobro en destino, línea de tiempo y foto del cargo.
- Seguimiento manual por estados; enlace público de seguimiento.
- Guía de remisión como paso opcional (puerto listo).

**Aceptación:** un pedido con dos despachos parciales muestra su línea de tiempo y saldos; el cobro en destino cuadra en caja.

**Hito M2 — "Del pedido a la entrega":** Fases 5 y 6.

### Fase 7 — Facturación electrónica (XL)
- `FiscalEngine` + adaptador eFact; aprovisionamiento por bridge; sincronización de series.
- Outbox, reintentos, circuito de corte, webhooks con HMAC y deduplicación, reconciliador.
- Pendientes y rechazados con motivos claros; anulación asimétrica.
- Cupo mensual; copia de XML/CDR/PDF; guía de remisión con eFact.
- Asistente de activación con entorno beta.

**Aceptación:** emisión end-to-end en beta; un corte de eFact no bloquea ventas y todo se reconcilia al volver; ningún comprobante duplicado ante reintentos.

**Hito M3 — "Facturación":** Fase 7.

### Fase 8 — Notificaciones, portal, panel y reportes (L)
- `Notifier` con Evolution; plantillas, reglas, consentimiento, límites y bitácora.
- Portal del cliente por enlace mágico; repetir pedido.
- Panel de inicio y reportes de v1.
- Marca blanca: logo, color, nombre, contacto, pie y dominio propio (hecho); plantillas de PDF por documento (pendiente; hoy se imprime desde el navegador).
- Tutoriales dentro de la aplicación y ayuda contextual.

**Aceptación:** el cliente recibe aviso por cada cambio de estado; el portal no expone datos de otros clientes (pruebas de seguridad); las plantillas respetan el logo subido.

**Hito M4 — "Comunicación":** Fase 8.

### Fase 9 — Endurecimiento y lanzamiento (M–L)
- Rendimiento con volumen (50 000 ítems, 100 000 documentos).
- Seguridad: revisión OWASP, prueba de intrusión básica, secretos, copias de seguridad y restauración.
- Observabilidad y alertas; panel de salud del proveedor.
- Documentación de usuario, términos, política de datos (Ley 29733).
- Beta cerrada con negocios reales y corrección.

**Hito M5 — Beta cerrada. Hito M6 — Lanzamiento v1.**

## 5. Después de v1
| Versión | Contenido |
|---|---|
| v1.5 | POS sin conexión (nivel 1), bot de WhatsApp determinista, integración con Olva o agregador, series por equipo |
| v2 | Asistente con IA opcional, orden de compra, lotes y series, reportes avanzados, API pública y MCP |
| v2+ | Extracción multi-país, contabilidad ligera |

## 6. Orden de dependencias
```
P → 0 → 1 → 2 → 3 → 4 → 5 ─┐
                  └────────→ 6 ├→ 7 → 8 → 9
                              ┘
```
- La Fase 7 puede adelantarse parcialmente (cliente y pruebas en beta) mientras se construye 5 o 6, si hay capacidad.
- La Fase 8 puede iniciarse con plantillas de PDF desde la Fase 3 (documentos no fiscales).

## 7. Recomendación de ajuste: esqueleto andante
Antes de completar la Fase 0, construir un recorrido mínimo, en un sprint corto:
1. Registro e inicio de sesión.
2. Crear empresa, sucursal y almacén.
3. Crear un producto simple.
4. Vender en un POS rudimentario con pago en efectivo.
5. Ver el movimiento en stock.
6. Imprimir un ticket.
7. Desplegar en staging con CI.

**Por qué:** valida tenancy, permisos, despliegue, estándares y componentes de UI antes de invertir en profundidad.

## 8. Primer sprint (arranque concreto)
| # | Tarea | Capa |
|---|---|---|
| 1 | Repositorio, CI con controles de formato, estático, pruebas y documentación | Infra |
| 2 | Proyecto Laravel modular + PostgreSQL; módulo `Platform` y migraciones base con `COMMENT` | API |
| 3 | Aislamiento: `tenant_id`, *global scope*, RLS y prueba de fuga entre tenants | API |
| 4 | Auth y actor; permisos mínimos | API |
| 5 | Auditoría estándar (trait, observer) y borrado lógico | API |
| 6 | SPA: cascarón con tema, rutas, internacionalización, componentes del contrato de interacción | Web |
| 7 | CRUD de empresa/sucursal/almacén con modales | Web + API |
| 8 | Producto simple, venta rudimentaria, movimiento de stock | Web + API |
| 9 | Despliegue a staging, semillas, documentación del repositorio | Infra |

### Avance del primer sprint (actualizado 2026-10-12)
| # | Estado | Notas |
|---|---|---|
| 1 | Parcial | Controles locales (`composer quality`, `pnpm quality`); falta CI remoto (no hay repositorio remoto) |
| 2 | Hecho | Esquema `core`, comentarios en tablas y columnas |
| 3 | Hecho | RLS forzado + pruebas de fuga; contexto por petición (`ResolveTenantContext`) |
| 4 | Hecho | Login por token (Sanctum), `me` con permisos, catálogo de permisos, perfiles plantilla, 403 por permiso |
| 5 | Hecho | `HasAudit` (autor, versión), `core.audit_log` inmutable, `LifecycleService` + `DeletionGuard` |
| 6 | Hecho | Cascarón, menú por permisos, login real, componentes del contrato |
| 7 | Hecho | Empresas (RUC con dígito verificador), sucursales y almacenes con ciclo de vida y guardia de borrado |
| 8 | Hecho | Producto con variante predeterminada y código de barras, stock append-only con costo promedio, venta con IGV (calculador compatible con eFact), numeración y anulación, POS web |
| 9 | Pendiente | Staging |

### Avance por fases (actualizado 2026-10-12)
| Fase | Estado | Notas |
|---|---|---|
| 3 Pedidos | Parcial | Pedidos con reserva, anticipo, estados, entrega parcial y cancelación. Cotizaciones con conversión a pedido. Edición de pedido sin entregas. Devolución de anticipos. Cola de armado básica |
| 4 Cobranzas y caja | Parcial | Crédito, cuentas por cobrar y cobro parcial; cajas, turnos, arqueo y caja chica. Ventas en espera (en el navegador) y devoluciones parciales. Cobro repartido entre varias deudas del cliente. Faltan notas de crédito fiscales |
| 5 Compras y pagos | Parcial | Proveedores, compras con ingreso de stock, pagos parciales, por pagar. Gastos con rubro y descuento de caja y devoluciones al proveedor. Falta aplicar el saldo a favor |
| 6 Envíos | Parcial | Agencias, envíos con estados y línea de tiempo, rótulo. Faltan adjuntos de la agencia, picking y guía de remisión |
| 7 Fiscal | Parcial | `FiscalEngine` + cliente Gavi eFact (idempotente con `external_id`), cola con reintentos, webhook con HMAC y deduplicación, bandeja y configuración. Reconciliador cada 10 min. Notas de crédito, baja y guías. Cupo mensual de comprobantes (aviso al 80 %, bloqueo de emisión al 100 % sin perder la venta). Falta aprovisionamiento por bridge |
| 1 Inventario | Parcial | Saldos, movimientos con costo promedio, ajustes, kardex, traslados entre almacenes y toma física. Faltan reservas por ubicación y lotes/series |
| 2 Catálogo | Parcial | Importación de productos desde Excel con validación previa. Variantes por combinación, listas de precios con escalas y etiquetas con código de barras. Catálogo de atributos con valores sugeridos |
| 8 Panel | Parcial | Panel de inicio con datos reales por permiso. Reportes de ventas, margen y resultado estimado. Avisos por WhatsApp (Evolution) con consentimiento. Recordatorio automático de deudas. Marca blanca (logo, color, nombre, dominio propio verificado, sincronía de logo con eFact). Falta portal del cliente con cuenta |

## 9. Puertas de calidad por fase
Una fase no se da por cerrada hasta cumplir:
1. Criterios de aceptación de la fase.
2. Controles de CI en verde y cobertura objetivo.
3. Pruebas de aislamiento de tenants y de permisos ampliadas con lo nuevo.
4. Documentación de código y de base de datos completa.
5. Textos de interfaz en archivos de traducción.
6. Migraciones probadas en limpio y en actualización.
7. Demostración con datos de un rubro (ropa, bodega, ferretería).
8. Actualización de `diseno/` si algo cambió.

## 10. Riesgos y mitigaciones
| Riesgo | Efecto | Mitigación |
|---|---|---|
| Alcance excesivo de v1 | No se llega a lanzar | Cortes por hito; M1 ya es utilizable; Fase 8 y 9 negociables |
| Divergencia de cálculo con eFact | Rechazos y descuadres | Pruebas de oro desde la Fase 3 y comparación continua |
| eFact o SUNAT caídos | Ventas bloqueadas | Cola, circuito de corte, emisión diferida |
| Aislamiento de tenants | Fuga de datos | RLS, pruebas dedicadas, revisión de cada consulta nueva |
| Evolution API bloqueada | Sin avisos | Puerto `Notifier`, correo de respaldo, límites de envío |
| Motor de PDF consume memoria | Servidor inestable | Spike P5, cola dedicada, límites |
| Persona única | Cuello de botella y riesgo de salud del proyecto | Documentación viva, estándares, automatización, alcance recortable |
| Modelo desconocido del mercado | Funciones que nadie usa | Beta cerrada temprana (después de M2) con 3–5 negocios |
| Complejidad de RBAC | Retrasos y errores de seguridad | Construir y probar en Fase 0 antes de cualquier pantalla |
| Datos mal migrados | Pérdida de confianza | Validación en seco, reversión por lote, plantilla con ejemplos |

## 11. Qué necesito definir contigo
1. **Capacidad:** ¿cuántas horas por semana y quién construye (solo tú, tú con Claude Code, más personas)? Con eso convierto tamaños en calendario.
2. **Servidor y dominio:** proveedor, región y presupuesto mensual.
3. **Nombre del producto** y dominio.
4. **Primer rubro de demostración** (ropa, bodega, ferretería).
5. **Candidatos para beta:** al menos 3 negocios dispuestos a probar después de M2.
6. **Repositorio:** ¿monorepo único (API + web) o separados?
