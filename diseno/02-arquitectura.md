# Arquitectura

Fecha: 2026-10-11. Estado: borrador. Base: `00-decisiones-base.md` (D1–D15) y `01-alcance-mvp.md`.

## 1. Fuerzas que mandan sobre el diseño

1. **Multi-tenant SaaS** con muchos negocios pequeños y pocos medianos.
2. **Venta primero, fiscal después:** emitir a SUNAT nunca bloquea vender.
3. **Pedido → venta → envío → cobro** es un hilo, con estados y anticipos, no tres módulos aislados.
4. **Muchos canales sobre las mismas reglas:** interfaz, POS, portal del cliente, WhatsApp, API pública (y a futuro bot de IA y MCP).
5. **Permisos finos desde el inicio** (perfil, menú, acción, alcance, límites).
6. **Equipo pequeño y servidor modesto:** simple de operar; separable más adelante.
7. **Conexión inestable en el punto de venta.**

## 2. Vista general

```
 Navegador / tablet / móvil                    Cliente final del negocio
 ┌──────────────────────────┐                  ┌────────────────────────┐
 │ SPA React (admin + POS)  │                  │ Portal (enlace mágico) │
 └────────────┬─────────────┘                  └───────────┬────────────┘
              │ HTTPS /api/v1                              │ /portal/v1
 ┌────────────▼────────────────────────────────────────────▼────────────┐
 │                       API Laravel (monolito modular)                  │
 │  Capa de servicios (casos de uso) + permisos por alcance              │
 │  Módulos: Plataforma · Catálogo · Inventario · Pedidos · Ventas ·     │
 │  Pagos · Envíos · Compras · Caja · Fiscal(pe_*) · Notificaciones ·    │
 │  Reportes · Importación                                               │
 └───┬──────────────┬───────────────┬──────────────┬─────────────────────┘
     │              │               │              │
 PostgreSQL       Redis        Almacén de        Colas / Scheduler
 (datos)       (cola, caché)   archivos (S3)    (workers Laravel)
                                                    │
                    ┌───────────────────────────────┼─────────────────┐
                    │ Gavi eFact (fiscal)           │ Evolution API   │
                    │ API + webhooks + bridge       │ (WhatsApp)      │
                    └───────────────────────────────┴─────────────────┘
```

Despliegue inicial: un servidor propio para el ERP, **separado del VPS de eFact** (no compartir fallos ni RAM).

## 3. Monolito modular

Un solo despliegue, módulos con fronteras claras.


| Módulo           | Responsabilidad                                                                                     | Depende de                         |
| ---------------- | --------------------------------------------------------------------------------------------------- | ---------------------------------- |
| Plataforma       | tenants, empresas, sucursales, usuarios, roles, permisos, auditoría, planes y cuotas, configuración | —                                  |
| Catálogo         | terceros, ítems, atributos y variantes, empaques, precios, categorías, códigos de barras, etiquetas | Plataforma                         |
| Inventario       | almacenes, movimientos (kardex), saldos, reservas, ajustes, traslados, toma física                  | Catálogo                           |
| Pedidos          | cotizaciones, pedidos, reservas, anticipos, armado                                                  | Catálogo, Inventario, Pagos        |
| Ventas           | documentos de venta, POS, notas de crédito/débito, anulaciones                                      | Pedidos, Inventario, Pagos, Fiscal |
| Pagos            | medios, cobros, pagos, aplicaciones, cuotas, CxC, CxP                                               | Plataforma                         |
| Envíos           | agencias, envíos, bultos, estados, cobro en destino                                                 | Pedidos, Ventas                    |
| Compras          | compras, gastos, CxP                                                                                | Catálogo, Inventario, Pagos        |
| Caja             | cajas, turnos, arqueo, movimientos                                                                  | Pagos                              |
| Fiscal (`pe_*`)  | catálogos SUNAT, `FiscalEngine`, outbox, webhooks, reconciliador                                    | Ventas, Envíos                     |
| Notificaciones   | `Notifier`, plantillas, preferencias, bitácora                                                      | Plataforma                         |
| Reportes / Panel | consultas de solo lectura                                                                           | todos (lectura)                    |
| Importación      | plantillas Excel, validación, saldos iniciales                                                      | Catálogo, Inventario, Pagos        |


**Reglas de frontera**

- Un módulo no lee tablas de otro; usa su **servicio público** o escucha sus **eventos de dominio**.
- Los eventos (`PedidoConfirmado`, `VentaConfirmada`, `EnvioEntregado`, `PagoRegistrado`…) se publican dentro de la transacción mediante *outbox*; los oyentes pueden ser síncronos (misma transacción) o asíncronos (cola).
- Cada módulo tiene su carpeta, sus migraciones y sus pruebas. Una prueba automática verifica que no haya dependencias prohibidas.

## 4. Multi-tenancy

- **Una base de datos compartida** con `tenant_id` en toda tabla de negocio, más `company_id` donde aplica.
- Jerarquía: `tenant` (cuenta del cliente) → `company` (RUC) → `branch` → `warehouse`. Una empresa que emite = un tenant en Gavi eFact.
- Aislamiento: *global scope* del ORM + **Row Level Security de PostgreSQL** como segunda barrera. Jobs y comandos establecen el tenant explícitamente.
- Índices siempre con `tenant_id` primero. Particionar por tiempo los libros grandes (movimientos de stock, eventos fiscales, auditoría).
- Migrar a una base por tenant solo si un cliente lo exige; el diseño no lo impide.
- Identificadores UUID v7.

### 4.1 Ámbitos de datos: qué es general y qué es por empresa o sucursal
Regla: **lo que identifica "qué es" va a nivel cuenta (tenant); lo que lleva "cómo y dónde se opera" va a empresa o sucursal.**

| Ámbito | Datos |
|---|---|
| **Cuenta (tenant)**, compartido entre empresas y sucursales | terceros (clientes y proveedores), ítems y variantes, categorías, marcas, atributos, unidades de medida, empaques, códigos de barras, catálogo de agencias de transporte, usuarios y perfiles, plantillas de mensajes |
| **Empresa (RUC)** | configuración tributaria y fiscal, credenciales de eFact, series y correlativos, listas de precios, condiciones comerciales por tercero (crédito, límite, lista), cuentas bancarias, medios de pago activos, plantillas de PDF y logo, numeración de cotizaciones y pedidos, documentos de venta y compra, cuentas por cobrar y por pagar |
| **Sucursal** | almacenes, **stock y movimientos**, cajas y turnos, puntos de venta, series asignadas, envíos de salida, alcance de usuarios |

Consecuencias:
- Un cliente o producto se crea **una vez** y se usa en todas las empresas y sucursales de la cuenta. Un ítem se **habilita por empresa** (tabla de activación) y su precio vive en la lista de la empresa.
- El stock nunca es general: pertenece a un almacén, y el almacén a una sucursal de una empresa. Un traslado entre empresas se modela como venta/compra, no como traslado interno.
- La deuda de un cliente se calcula **por empresa**; el estado de cuenta consolidado de la cuenta es solo un reporte.
- Por defecto un tercero es visible en todas las empresas de la cuenta; un indicador permite restringirlo a algunas (para dueños con negocios no relacionados).
- Los límites del plan incluyen: número de empresas, sucursales por empresa, usuarios, ítems y emisión por empresa.

## 5. Identidad, roles y permisos

- Un **usuario** pertenece a un tenant y se asigna a una o varias empresas con un **perfil**.
- **Catálogo de permisos** `modulo.recurso.accion` en código; los perfiles se crean por tenant con plantillas iniciales.
- Dimensiones:
  1. **Acción** (ver, crear, editar, archivar, anular, exportar, aprobar).
  2. **Menú**: se deriva de las acciones permitidas.
  3. **Alcance**: sucursal, almacén, caja.
  4. **Límites numéricos** (descuento máximo, monto de anulación) con **aprobación de supervisor**.
  5. **Excepciones por usuario** sobre el perfil.
- Se evalúa en el servidor con políticas en la capa de servicios. La interfaz solo oculta.
- Cambios de permisos y acciones sensibles van a auditoría.
- Autenticación: sesión/token con renovación, doble factor opcional para administradores, bloqueo por intentos.

## 6. Capa de servicios y "actores"

Todo caso de uso es una clase (`ConfirmarPedido`, `RegistrarCobro`, `EmitirComprobante`…) que recibe un **actor** y datos validados.

```
Actor { tipo: usuario | cliente_portal | bot | api_key | sistema,
        tenant, empresa, alcance[], permisos[] }
```

- La interfaz, el POS, el portal, la API pública, el bot de WhatsApp y un futuro MCP **llaman a los mismos casos de uso**.
- El actor `cliente_portal` solo puede ver lo suyo (pedidos y saldo del tercero verificado).
- Los controladores solo traducen HTTP ↔ caso de uso. Sin reglas de negocio en controladores.
- Toda operación que cambia estado es **idempotente** con `Idempotency-Key` (importante para POS con red inestable).

## 7. Flujo pedido → venta → envío → cobro

### Máquinas de estado


| Entidad                  | Estados                                                                                                  |
| ------------------------ | -------------------------------------------------------------------------------------------------------- |
| Cotización               | borrador → enviada → aceptada | rechazada | vencida                                                      |
| Pedido                   | borrador → confirmado → en armado → listo → parcialmente despachado → despachado → entregado | cancelado |
| Venta                    | borrador → confirmada → anulada (con reglas fiscales)                                                    |
| Envío                    | por despachar → despachado → en tránsito → en agencia destino → entregado | devuelto | incidencia        |
| Fiscal                   | sin emisión → por emitir → emitiendo → aceptado | rechazado | observado → anulación en proceso → anulado |
| Cobranza (por documento) | pendiente → parcial → pagado | vencido                                                                   |


### Reglas clave

- **Reserva:** al confirmar el pedido se reserva stock (`stock_reservations`); al despachar se convierte en salida; al cancelar se libera.
- **Anticipos:** un cobro puede nacer ligado a un pedido y luego **aplicarse** a la venta; si hay comprobante, se emite como anticipo/descuento según el motor fiscal.
- **Entregas parciales:** un pedido puede tener varias ventas y varios envíos; cada línea guarda cantidades pedida, reservada, despachada y facturada.
- **Estado dividido:** venta confirmada y fiscal rechazado coexisten; el rechazo abre una tarea de corrección, no revierte la venta.
- **Anulación asimétrica:** boleta aceptada → resumen o NC; factura → baja o NC; boleta no enviada → anulación local. Es una máquina de estados, no un botón.
- Cada transición registra **quién, cuándo y por qué** (evento + auditoría) y dispara notificaciones configurables.

## 8. Inventario y pagos

- **Movimientos append-only** (kardex): cada fila con ítem/variante, almacén, tipo, cantidad, costo, documento origen. Los saldos son una proyección actualizada en la misma transacción y reconstruible.
- Costeo promedio ponderado en v1; el modelo permite FIFO.
- `controla_stock` por empresa e ítem; stock negativo según política.
- **Pagos:** `payments` (dinero recibido o entregado) y `payment_allocations` (a qué documento/cuota se aplica). Un pago mixto = varios pagos; un cobro puede cubrir varios documentos. CxC y CxP se calculan desde documentos, cuotas y aplicaciones, nunca se editan a mano.
- **Caja:** un turno agrupa movimientos en efectivo; el arqueo compara conteo (por denominación o monto final) contra lo esperado.

## 9. Fiscal (módulo `pe_*` y `FiscalEngine`)

- Puerto `FiscalEngine` con adaptador `GaviEfact`. El núcleo no conoce columnas SUNAT salvo mediante este módulo.
- **Outbox fiscal:** al confirmar una venta con comprobante se crea un `fiscal_documents` en `por_emitir`; un worker lo envía con `external_id` = id del documento (idempotente), reintentos con retroceso y *circuit breaker*.
- **Estado final** por webhook firmado (HMAC) con deduplicación por `X-Gavi-Event-Id` y **polling de respaldo**; **reconciliador** diario contra eFact.
- **Aprovisionamiento:** al activar la emisión de una empresa, `POST /api/bridge/provision` crea el tenant (prueba), sucursales y series; se guardan `api_key`, `api_secret` y `webhook_secret` **cifrados**. Plan y pago: manual en el panel de eFact en v1.
- **Montos fiscales** que devuelve la API se guardan y se comparan con los del ERP; diferencia → alerta.
- Copia propia de XML, CDR y PDF en el almacén de archivos.
- Cupo mensual: lectura de `/suscripcion/uso`; el ERP bloquea o avisa antes del límite. Sin plan de emisión: modo fiscal `none`.
- **Guía de remisión:** paso opcional del envío, configurable por empresa.
- Salud: estado SUNAT, cola y cupo visibles en el panel del proveedor.

## 10. Notificaciones y WhatsApp

- Puerto `Notifier` (canales: WhatsApp, correo, interno). Adaptador `EvolutionApi`.
- **Eventos → plantillas → cola.** Preferencias y consentimiento por tercero; límites de envío por número y por hora; reintentos; bitácora de entrega.
- Tres audiencias: cliente final, dueño del negocio, proveedor del SaaS.
- Webhook entrante de Evolution (v1.5): bot determinista que resuelve consultas (estado, saldo, comprobante) llamando casos de uso con actor `bot` ligado al teléfono verificado.
- Si WhatsApp falla: correo o aviso interno; ninguna operación de negocio depende del envío.

## 11. Portal del cliente

- Acceso por **enlace mágico** firmado de corta vida, reenviable por WhatsApp; sin contraseña.
- Ve: pedidos y estados, línea de tiempo de envío, comprobantes, saldo, "repetir pedido".
- Limitación por IP y por token; el actor `cliente_portal` queda restringido al tercero.
- Enlaces públicos de seguimiento por pedido (solo lectura, token largo) para compartir.

## 12. Archivos

- Almacén compatible con S3 (local en desarrollo, bucket en producción) con rutas por tenant.
- Tipos: XML/CDR/PDF, imágenes de producto, fotos de cargo de entrega, comprobantes de pago, importaciones.
- URLs firmadas de corta vida; nunca públicas.

## 13. Importación

- Plantillas Excel por entidad (ítems con variantes, terceros, saldos de stock, CxC, CxP).
- Flujo: subir → mapear columnas → **validación en seco con reporte de errores por fila** → confirmar → procesar en cola → resumen y reversión por lote.
- Los datos de demostración son un lote marcado, borrable en un paso.

## 14. Interfaz

- SPA React con el tema `aura-admin-theme` como kit; **TanStack Router/Query** para rutas y datos (sin SSR).
- API REST JSON versionada (`/api/v1`), paginación por cursor, filtros declarativos, errores uniformes `{codigo, mensaje, campos}`.
- Regla de pantallas: modal (CRUD simple), panel lateral (medio), página completa (documentos), POS propio.
- Mobile first en POS, pedidos y consulta; contratos de API pensados para tablets.
- Tokens de diseño en CSS (compacto, corporativo); modo claro y oscuro.

## 15. Colas y tareas programadas


| Cola             | Uso                       | Prioridad |
| ---------------- | ------------------------- | --------- |
| `fiscal`         | envío a eFact, reintentos | alta      |
| `notificaciones` | WhatsApp y correo         | media     |
| `importaciones`  | cargas masivas            | baja      |
| `reportes`       | exportaciones pesadas     | baja      |
| `default`        | eventos asíncronos        | media     |


Programadas: reconciliador fiscal, recordatorios de cuotas por vencer, alertas de stock, cierre de cupo mensual, limpieza de enlaces vencidos, resumen diario al dueño.

## 16. Seguridad y privacidad

- Cifrado en reposo de credenciales (SOL, API de eFact, Evolution) con clave gestionada fuera de la base.
- Límites de tasa por tenant, por usuario y por IP; bloqueo por intentos fallidos.
- Auditoría inmutable de acciones sensibles; registro de accesos.
- Datos personales: finalidad, consentimiento para notificaciones, exportación y eliminación por tenant (Ley 29733).
- Copias de seguridad diarias con prueba de restauración; retención de comprobantes según norma.
- Entrada del portal y del bot tratada como no confiable.

## 17. Observabilidad y operación

- Registros estructurados con `tenant_id` y `request_id`; trazas de las llamadas a eFact y Evolution.
- Métricas: latencia de POS, cola pendiente, comprobantes por estado, cupo por tenant.
- Alertas: cola fiscal estancada, tasa de rechazo, SUNAT caída, WhatsApp desconectado.
- **Panel del proveedor:** tenants, plan interno, vencimiento, registro manual de cobros, suspensión, uso de cupo de emisión, salud de integraciones.

## 18. Requisitos no funcionales (objetivos a validar)


| Aspecto              | Objetivo inicial                                 |
| -------------------- | ------------------------------------------------ |
| Venta en POS         | &lt; 1 s respuesta de API en operaciones típicas |
| Búsqueda de producto | &lt; 300 ms con catálogos de 50 000 ítems        |
| Disponibilidad       | 99.5 % mensual                                   |
| Recuperación         | RPO 24 h, RTO 4 h (v1)                           |
| Importación          | 5 000 filas en &lt; 5 min                        |


## 19. Extracción futura

- `pe_*` reemplazable para otros países.
- Candidatos a servicio propio al crecer: Notificaciones, Fiscal (outbox y cliente), Reportes. El contrato es el evento de dominio y el caso de uso.

## 20. Decisiones técnicas pendientes (ADR)

1. Versión y paquetes base de Laravel (autorización, colas, auditoría, multi-tenancy propia o paquete).
2. Estrategia de RLS y su impacto en jobs.
3. POS sin conexión: almacenamiento local y resolución de conflictos (v1.5).
4. Motor de generación de PDF/etiquetas/tickets propio vs. el de eFact.
5. Proveedor de almacenamiento y de correo.
6. Esquema de versionado de la API pública.

