# Revisión de Gavi eFact (`plataform-api-sunat`) como motor fiscal del ERP

Fecha: 2026-10-11. Fuente: `D:\Proyectos PHP\plataform-api-sunat` (`CONTEXT.md`, `documentacion/01…28`, `routes/api.php`) más el OpenAPI público.
Alcance leído: 01, 02, 04, 05, 06, 08, 09, 10, 11 (parcial), 12, 17, 22, 24, 26 (parcial), 28 y `CONTEXT.md`. No leídos a fondo: 03 NRUS, 13, 14, 15, 16, 18–21, 23, 25, 27.

## 1. Hallazgo principal
El OpenAPI público (`efact.gavi-cloud.com/openapi.json`) es un **subconjunto**. El servicio real tiene ~160 rutas y cubre mucho más que facturar: retenciones, percepciones, reversiones, SIRE (compras), clientes, sucursales, series, cotizaciones, notas de venta, pagos, panel, reportes, catálogo UNSPSC, consulta pública, planes. **Eso lo convierte en motor fiscal y, a la vez, en un mini-sistema competidor del ERP.** Hay que decidir qué usar y qué ignorar (ver §6).

## 2. Qué hace (resumen técnico)

| Aspecto | Detalle |
|---|---|
| Stack | Laravel 12, Greenter (SOAP SUNAT), cola `database`/Redis, MySQL o PostgreSQL |
| Tenancy | Un tenant por RUC; credenciales SOL, certificado y GRE por tenant (cifradas) |
| Auth | `X-Api-Key` + `X-Api-Secret`; `api_secret` se guarda en claro (decisión explícita por cómo valida el middleware) |
| Respuesta | `{estado: exito\|error, mensaje, datos, meta?, errores?}` |
| Estados | `pendiente → enviado → aceptado \| rechazado`; `anulacion_en_proceso → anulado`; GRE y resúmenes añaden `procesando` |
| Idempotencia | `external_id` único por empresa y tipo (facturas, boletas, NC, ND): repetido igual → 200 + `Idempotent-Replayed`; distinto → 409 |
| Webhooks | HMAC-SHA256 de `"{ts}.{cuerpo}"`; cabeceras `X-Gavi-Event-Id/Timestamp/Signature`; 3 reintentos (10/30/90 s); solo planes Negocio/Empresa |
| Resiliencia | 20 reintentos (~15 h), circuit breaker, `GET /sunat/estado`; caída SUNAT = `pendiente` + `SUNAT_TIMEOUT`, no rechazo |
| Archivos | XML, CDR, PDF (A4, A5, ticket 80/58), ZIP masivo |

## 3. Respuesta a los 7 puntos abiertos de `00-decisiones-base.md`

1. **Pagos, descuentos, cargos, anticipos, cuotas**
   - `pagos[]`: `{metodo, monto, referencia?, monto_recibido?, notas?}`; métodos `efectivo, yape, plin, tunki, transferencia, tarjeta_credito, tarjeta_debito, deposito, cheque, otro`. Endpoints `POST|GET|DELETE /facturas|boletas/{id}/pagos` y `estado_pago` (`pendiente, parcial, pagado`).
   - Crédito: `forma_pago=Credito` + `fecha_vencimiento` + `cuotas[{monto, fecha_pago}]`.
   - Descuento por ítem: `items[].descuentos[{cod_tipo 00|01, monto | porcentaje | factor}]`.
   - Globales: `descuentos_globales[]` (Cat. 53: 02, 03, 04–06) y `cargos_globales[]` (49, 50).
   - Anticipos: `anticipos[{tipo_doc, serie, correlativo, monto}]` + `total_anticipos`.
   - También: `guias[]` (relacionar GRE), `leyendas[]`, `contrato_colaboracion`, `isc/porcentaje_isc/tip_sis_isc`, `icbper/factor_icbper`.
2. **Detracción, retención, percepción**
   - Detracción: `tipo_operacion="1001"` + `detraccion{cod_bien, porcentaje, cta_banco, cod_medio_pago, monto}`; aplica si total > S/ 700; Cat. 54 incluye 038, 042, 043, 046, 047 desde 01/09/2026.
   - Percepción en venta: `tipo_operacion="2001"` + `percepcion{cod_regimen, porcentaje, monto, base}`.
   - Retención **emitida** (tipo 20), percepción **emitida** (40) y reversión (RR): rutas propias, solo plan **Empresa**.
   - Retención **sufrida** (cuando el cliente nos retiene): no aparece; es tema de cobranzas del ERP.
3. **Nota de crédito/débito**
   - Requieren `doc_afectado_*`, `cod_motivo`, `des_motivo` **e ítems** (lo que se revierte; parcial por ítem permitido).
   - Regla: NC ≤ monto del documento (factura estricta, boleta +1); no aplica si cambia la moneda o el documento es de otro sistema.
   - ND motivo `13` (penalidades): solo inafectas, documento afectado opcional, SUNAT lo activa el 01/01/2027.
   - Serie: NC `FC01/BC01` o `F…/B…`; ND `FD01`.
4. **Boletas**
   - Por defecto cada boleta se envía individual. `solo_registro: true` la deja para un **resumen diario** (`POST /resumenes`, plazo 7 días).
   - Anular boleta aceptada: resumen en modo `anular[]` (≤ 7 días) o NC.
5. **Qué cubre / no cubre**
   - **Cubre:** emisión CPE y guías, resumen/baja, retención/percepción/reversión, **SIRE RCE** (propuesta, aceptar, reemplazar, no domiciliados, ajustes, reconciliación; 25 endpoints), consulta CPE/CDR, RUC/DNI, UNSPSC.
   - **No cubre:** registro de compras propio (usa SIRE de SUNAT), RVIE de ventas como flujo (el libro `rvie` figura en tablas pero solo hay endpoints RCE), PLE, contabilidad, inventario, planilla.
6. **Límites por plan** (vigentes)

   | | Emprendedor S/30 | Negocio S/59 | Empresa S/130 |
   |---|---|---|---|
   | Comprobantes SUNAT/mes | 200 | 1,000 | 5,000 |
   | Docs internos/mes | 100 | 500 | ilimitados |
   | Sucursales | 1 | 3 | 10 |
   | GRR/GRT | — | ✅ | ✅ |
   | Webhooks, ZIP, reportes avanzados | — | ✅ | ✅ |
   | Retención/percepción/reversión, SIRE | — | — | ✅ |
   | Prueba | 14 días, 50 docs, funciones de Negocio | | |

   Errores: `403` plan/cupo, `429` rate limit. Sin plan vigente = 0 documentos. No hay cobro de excedente ni multiusuario por empresa.
7. **Series y sucursales**
   - Se crean **en el SaaS** (`POST /sucursales`, `POST /series` con `tipo`, `serie`, `sucursal_id`, `correlativo_inicial`). El correlativo lo **asigna el SaaS** por serie y tenant.
   - Prefijos: factura `F`, boleta `B`, NC/ND `F…`/`B…`, GRR `T`/`EG`, GRT `V`, retención `R`, percepción `P`.
   - `sucursal_id` es opcional en el POST: sin él el documento no queda asociado a sucursal (el ERP debe enviarlo siempre).

## 4. Inconsistencias detectadas en la documentación (corregir o confirmar)

| # | Dónde | Problema |
|---|---|---|
| 1 | `01-Configuracion` | Planes `free/pro/business` (y límites 30/200/ilimitado) contradicen `CONTEXT.md` y `26` (Emprendedor/Negocio/Empresa) |
| 2 | `06-Notas-credito` vs `09-Anular` | `06` dice que una NC no se anula con baja; `09` incluye `07` y `08` en la comunicación de baja |
| 3 | `12-Envio-manual` | Reintentos "10 con backoff 15 s→600 s"; `22` y `CONTEXT` dicen 20 intentos, ~15 h |
| 4 | `04` y `22` | Plazo de baja 7 días (facturas) y resúmenes 7 días vs. "1 año para anular" en `06` (aplica a NC como alternativa) |
| 5 | `04-Facturas` | `descuentos` por ítem usa `sunat_status`; la respuesta nueva anida `datos.sunat.estado`. Los filtros siguen siendo `sunat_status` |
| 6 | `guías` | Respuesta GRR usa `sunat_status`/`sunat_ticket` planos, no anidados como CPE |
| 7 | OpenAPI público | Omite `enviar_automatico`, `solo_registro`, descuentos globales, cuotas, detracción, percepción, anticipos, retenciones, SIRE y resúmenes en modo envío |
| 8 | `CONTEXT.md` | Producción citada como `apisunatv2.kodevo.es`; el OpenAPI declara `apifact.gavi-cloud.com`. Confirmar host y que `kodevo` es legado |
| 9 | `README` | Motor de BD: README MySQL vs `.env.example` pgsql |

## 5. Riesgos operativos del propio SaaS (afectan al ERP)

- ~~`routes/web.php` tiene rutas públicas temporales (`/limpiar-cache-ahora`, `/ver-errores`)~~ **Corregido (verificado 2026-10-11 en `routes/web.php`):** ahora exigen sesión `auth` + `admin`. Los comentarios del código dicen "antes eran públicas". Verificar que el despliegue en producción corresponda a esta versión.
- `api_secret` en claro en la base de datos.
- Almacenamiento de certificados y XML en `storage/app` del contenedor: si no es volumen persistente se pierden al redesplegar.
- Worker de cola y scheduler son opcionales (`RUN_QUEUE_WORKER`, `RUN_SCHEDULER`): sin ellos "se crean pero no llegan a SUNAT".
- 38 tests fallando de antes; faltan tests de Actions y Greenter.
- Un único VPS (6 vCPU, 12 GB): punto único de falla para todos los clientes.

## 6. Decisión de uso: qué tomar y qué ignorar

| Capacidad del SaaS | Postura del ERP |
|---|---|
| Emisión CPE, NC/ND, guías, resumen, baja | **Usar** (núcleo de la integración) |
| Retención, percepción, reversión | Usar si el ERP las necesita (plan Empresa); no es MVP |
| SIRE RCE | **Usar** como fuente/validador del registro de compras; el libro y su conciliación viven en el ERP |
| `buscar-documento` RUC/DNI | Usar (alta de terceros) |
| Consulta CPE/CDR, ZIP | Usar |
| Clientes, cotizaciones, notas de venta, pagos, panel, reportes | **No usar:** el ERP es la fuente de verdad; duplicarlos crea dos verdades |
| Planes/suscripción | Usar solo para leer cupo (`/suscripcion/uso`) y degradar con gracia |

## 7. Qué debe construir el ERP encima

1. `FiscalEngine` (puerto) + `GaviEfactClient` con: creación idempotente (`external_id = documento ERP`), reintentos con backoff, manejo `401/403/409/422/429/5xx`, circuit breaker propio.
2. Cola saliente local (outbox) por documento: `borrador → por_emitir → emitiendo → aceptado | rechazado | observado`, sin bloquear la venta.
3. Receptor de webhooks: verificación HMAC (±300 s), deduplicación por `X-Gavi-Event-Id`, responder 2xx rápido, procesar async. Más **polling de respaldo** para documentos sin estado final.
4. Sincronizador de maestros: sucursales y series hacia el SaaS al crear una sucursal/caja; traer correlativos asignados.
5. Reconciliador diario: compara documentos locales vs. `GET /facturas?external_id=` y `consultar-cpe`.
6. Pantallas: pendientes de envío, rechazados con código/mensaje de SUNAT, corrección + reintento, descarga de XML/CDR/PDF.
7. Almacén propio de XML/CDR/PDF (copia) para no depender del volumen del SaaS.
8. Panel de salud: estado SUNAT (`/sunat/estado`), cola del SaaS, uso de cupo.

## 8. Peticiones recomendadas al propio SaaS (backlog)

1. Publicar el OpenAPI completo (todas las rutas y campos).
2. Cifrar `api_secret` (las rutas `/limpiar-cache-ahora` y `/ver-errores` ya exigen admin).
2b. **Bridge de aprovisionamiento ya existe** (`POST /api/bridge/provision`, `bridge/auth`, `DELETE bridge/tenant`, cabecera `X-Bridge-Key`): crea el tenant con prueba gratis, sucursales y series (tipos 01, 03, 07, 08) y devuelve `api_key`, `api_secret` y `webhook_secret` solo al crear. **Falta** un endpoint para asignar/cambiar plan y registrar pago (hoy solo desde el panel admin `empresas/{tenant}/pagos`).
3. Endpoint de **correlativos/series** y de **uso/cupo** estable para el ERP.
4. Campo `external_id` también en guías de remisión, resúmenes y bajas.
5. Webhooks disponibles en todos los planes (o al menos eventos de error) para clientes del ERP.
6. Unificar nombres de estado (`sunat.estado` vs `sunat_status`) en todos los recursos.
7. Documento de versionado y cambios (changelog) del API.
8. Entorno `beta` por tenant con certificado global, ya documentado: hacerlo parte del onboarding del ERP.
