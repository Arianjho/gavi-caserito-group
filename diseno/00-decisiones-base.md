# Decisiones base del proyecto

Fecha: 2026-10-11. Estado: acordadas con el usuario; se revisan solo con decisión explícita.

## D1. Modelo nuevo; los proyectos previos solo son referencia
`gavi-efact-v3`, `sistema_pro6` y `smart` se usan para entender el dominio, no se extienden ni se copian. El esquema nuevo se diseña desde cero (ver `investigacion/02-datos-requeridos-erp.md` para qué datos cubrir y qué errores evitar).

## D2. Solo Perú (v1)
- Los maestros SUNAT (catálogos 01, 03, 05, 06, 07, 09, 10, 16, 17, 18, 20, 25, 54, 59, ubigeo), PCGE, PLE/SIRE y PLAME son **nativos** del modelo, sin capa de abstracción de país.
- Consecuencia: claves de catálogo = código SUNAT; tasas IGV/ISC/ICBPER como datos con vigencia; ubigeo INEI.
- **Preparar la extracción futura, no construirla:** los catálogos y reglas SUNAT viven en un módulo/esquema propio (`pe_*`) y el núcleo (empresas, terceros, ítems, documentos, stock, caja, finanzas) no referencia columnas SUNAT salvo mediante ese módulo. Una extensión multi-país futura reemplaza ese módulo, no el núcleo.

## D3. Facturación electrónica = SaaS externo propio (Gavi eFact)
- El ERP **no firma ni envía XML**: delega en `https://apifact.gavi-cloud.com/api/v1` (especificación en `https://efact.gavi-cloud.com/openapi.json`).
- Se modela como **puerto/adaptador** (`FiscalEngine`) con una implementación `GaviEfact`. El resto del ERP solo habla con el puerto.
- Regla heredada de `gavi-efact-v3`: la venta se confirma de forma independiente a la emisión; la emisión es un paso posterior, asíncrono, reintentable.

### Contrato de integración (lo que sabemos de la API v1.0.0)
| Tema | Detalle |
|---|---|
| Auth | Cabeceras `X-Api-Key` + `X-Api-Secret` por empresa emisora (cifrar en reposo; hay endpoint para regenerar) |
| Idempotencia | `external_id` en el POST evita duplicados al reintentar. Usar el UUID del documento comercial del ERP |
| Asíncrono | POST responde 201 con `sunat.estado = pendiente`; el estado final llega por **webhook** (plan Negocio, firmado con `whsec_…`, responder 200 en < 15 s) o por consulta `GET` |
| Precio | `precio_unitario` se envía **con IGV incluido**; la API separa base e IGV |
| Documentos | Facturas, boletas, notas de crédito/débito, guías de remisión (remitente y transportista), resumen diario (anular boletas), comunicación de baja |
| Archivos | XML firmado, CDR y PDF por documento; ZIP masivo de comprobantes |
| Consultas | `consultar-cpe`, `consultar-cdr`, `buscar-documento` (RUC/DNI, primero local luego SUNAT/RENIEC) |
| Errores | 401 credenciales, 403 plan o cupo, 422 validación (`errores`), 429 rate limit |
| Numeración | La API devuelve `correlativo` y `numero_completo`: el correlativo lo asigna el servicio |

### Qué debe guardar el ERP por cada documento fiscal
`fiscal_documents`: id local, `external_id`, id remoto, tipo SUNAT, serie, correlativo, estado SUNAT (pendiente / aceptado / rechazado / anulado), código y descripción de error, montos devueltos por la API (fuente fiscal), rutas XML/CDR/PDF, intentos, `sent_at`; más `fiscal_events` con cada llamada, webhook y reintento (para auditoría).

### Puntos que estaban abiertos: resueltos (ver `investigacion/03-gavi-efact-api-revision.md`)
1. `pagos`, descuentos por ítem y globales, cargos, anticipos y cuotas de crédito: **soportados**.
2. Detracción (`tipo_operacion 1001` + bloque `detraccion`), percepción en venta (`2001`), retención/percepción emitidas y reversión (plan Empresa): **soportados**. La retención *sufrida* es cobranza del ERP.
3. NC/ND: requieren ítems; parcial por ítem permitido; NC ≤ monto del documento afectado.
4. Boletas: envío individual por defecto; `solo_registro=true` para resumen diario (7 días).
5. **Cubre:** CPE, guías GRR/GRT, resumen, baja, retención/percepción/reversión, SIRE RCE. **No cubre:** contabilidad, PLE, inventario, planilla, flujo RVIE completo.
6. Planes: 200 / 1,000 / 5,000 comprobantes al mes; `403` por plan o cupo, `429` por rate limit; el ERP degrada con cola local.
7. Series y sucursales se crean **en el SaaS**; el correlativo lo asigna el SaaS.

### Uso del SaaS
El ERP usa solo la función fiscal (emisión, estados, archivos, SIRE, consultas). Clientes, cotizaciones, notas de venta, pagos, panel y reportes del SaaS **no se usan**: el ERP es la única fuente de verdad comercial.

### Puntos de atención nuevos
- Las rutas `/limpiar-cache-ahora` y `/ver-errores` del SaaS ya exigen administrador en el código actual; confirmar que producción corre esa versión.
- **Aprovisionamiento por API (recomendado):** el ERP llama `POST /api/bridge/provision` (`X-Bridge-Key`) al activar la emisión de una empresa: crea tenant en prueba, sucursales y series y devuelve credenciales. La asignación de plan/pago sigue manual en el panel de eFact en v1 (pedir endpoint de plan al backlog del SaaS).
- Contradicciones de documentación del SaaS (planes, anulación de NC, reintentos): ver tabla en el doc 03.
- El ERP guarda copia propia de XML/CDR/PDF y corre su propio reconciliador.

### Riesgos de diseño derivados
- **Doble cálculo de totales:** el ERP calcula para mostrar y contabilizar; el SaaS recalcula. Guardar los montos que devuelve la API y alertar si difieren (redondeo).
- **Estado dividido:** el documento comercial puede estar "confirmado" y el fiscal "rechazado"; se necesita una cola de pendientes y una pantalla de correcciones.
- **Anulación asimétrica:** boleta aceptada → resumen; factura → comunicación de baja; boleta no enviada → borrado local. Es una máquina de estados, no un botón.
- **Dependencia disponible:** POS debe seguir vendiendo con el SaaS caído (cola, emisión diferida, numeración provisional de ticket).

## D4. Producto: sistema de ventas SaaS; la emisión SUNAT es opcional
- Posicionamiento: **sistema de ventas** (POS + inventario + caja + cobranzas). La facturación electrónica es un módulo activable por empresa, no el centro.
- Modo fiscal por empresa: `none` (solo notas de venta/tickets internos) | `optional` (el cajero elige) | `required` (siempre comprobante).
- Documento comercial único con tipo: `nota_venta` (interno, serie propia, rotulado "no es comprobante de pago") o `boleta`/`factura` (pasan por `FiscalEngine`). Una nota de venta puede convertirse después en boleta/factura.
- Primer público: emprendedores y negocios pequeños (bodegas, tiendas, servicios). Prioridad: rapidez de registro y venta, no profundidad contable.

## D5. Planes: dos ejes independientes
1. **Plan funcional** (usuarios, sucursales, productos, módulos): existe sin emisión.
2. **Emisión SUNAT** (complemento por cupo mensual de comprobantes): se vende aparte porque el costo marginal es del SaaS Gavi eFact (S/30→200 docs = S/0.15/doc; S/59→1,000 = S/0.059; S/130→5,000 = S/0.026).
- Un tenant del SaaS Gavi = un RUC. El ERP necesita aprovisionar/gestionar una suscripción de eFact por empresa que emite (pendiente: confirmar si hay API de alta de tenant/plan).
- El ERP aplica el cupo propio (`/suscripcion/uso` como verificación) y bloquea o vende paquete adicional; el SaaS no cobra excedente.

## D6. Reglas de producto (catálogos y UI)
- Ítem: `tipo` bien|servicio. Unidad de medida = Cat. 03 SUNAT; por defecto `NIU` (bien) y `ZZ` (servicio). Servicio no controla stock.
- Afectación IGV = Cat. 07 SUNAT; el valor por defecto sale de la configuración tributaria de la empresa (régimen y si es afecta a IGV), editable por ítem. La UI muestra nombres amigables ("Gravado con IGV"), no códigos.
- Códigos de barras: tabla propia (varios por ítem); el lector USB funciona como teclado; cámara en móvil.
- UI: tema base `aura-admin-theme` (ver análisis de reutilización abajo); modal para CRUD simple, panel lateral para medio, página completa para documentos transaccionales; POS en pantalla propia.
- "Eliminar" = archivar/desactivar si el registro tiene movimientos; borrado físico solo si está vacío.
- Stack de aplicación (decidido 2026-10-11): **API Laravel + SPA React** con los componentes del tema. Arquitectura inicial: **monolito modular** (módulos con fronteras claras y comunicación por contratos/eventos), separable en servicios más adelante. No "monolito distribuido": eso es el antipatrón de servicios acoplados.

## D8. Caja opcional y configurable
Por empresa/caja: sin apertura, o con apertura/cierre. Arqueo por conteo de denominaciones PEN o por monto final directo. Ver `01-alcance-mvp.md` §5.

## D9. Contado/crédito y pagos
Ventas y compras tienen condición `contado` | `crédito`. Crédito genera cuenta por cobrar o por pagar con vencimiento y cuotas. Pago mixto en cualquier documento. `controla_stock` configurable por empresa e ítem.

## D10. Notificaciones por WhatsApp (Evolution API)
Un número del sistema como bot, tras un puerto `Notifier`. Destinatarios: cliente final, dueño del negocio y proveedor del SaaS. Riesgo: canal no oficial, posible bloqueo; requiere opt-in, límites, reintentos y canal alterno.

## D11. Roles y permisos complejos desde v1
Permisos `modulo.recurso.accion` por perfil, menú y acción; alcance por sucursal/almacén/caja; límites numéricos con aprobación; excepciones por usuario; verificación en servidor; auditoría.

## D12. Cobro y panel del proveedor (v1)
Sin pasarela. El proveedor registra los cobros manualmente desde su panel SaaS (tenants, plan, vencimiento, suspensión, cupo de emisión). La suscripción de eFact se revende dentro del plan del cliente.

## D14. Pedidos y envíos son núcleo; un solo producto multi-rubro
Pedido y envío son entidades propias (reserva, anticipos, entregas parciales, línea de tiempo). Portal del cliente por enlace mágico y avisos por WhatsApp. Variantes por atributos definidos por el negocio (talla, color, tamaño…), empaques y precios por escala son núcleo. Seguimiento de agencias manual en v1 (puerto `CarrierTracker`). Ver `investigacion/04-mercado-pedidos-y-envios.md`.

## D15. IA y MCP: fuera de v1; diseñar para ellos
v1 expone una **capa de servicios con permisos por alcance** (la misma para UI, portal, bot y API) para que bot y MCP sean canales futuros, no reescrituras. Orden: bot de WhatsApp determinista (consultas de estado, saldo, comprobante) → asistente con LLM opcional como complemento de plan → MCP como envoltorio fino de la API pública.

## D16. Cuenta con varias empresas; ámbitos de datos
Una cuenta (tenant) puede tener **varias empresas (RUC)**, cada una con sucursales y almacenes (modelo similar a Treinta). El número de empresas, sucursales, usuarios e ítems se limita por plan. Clientes y productos son **generales de la cuenta** (se habilitan por empresa); series, inventario, movimientos, cajas, listas de precios y documentos son por empresa y sucursal. Detalle en `02-arquitectura.md` §4.1. Una empresa que emite = un tenant en Gavi eFact.

## D17. Contrato de interacción de la interfaz
Skeleton en cargas; formularios bloqueados (campos, botón con verbo de proceso y cierre del diálogo) mientras corre un endpoint; tiempo máximo con salida segura y reintento idempotente; toasts de éxito, advertencia y peligro con lenguaje de negocio y diccionario de errores. Detalle en `05-sistema-visual.md`.

## D18. Marca blanca y plantillas de PDF
Logo y color por empresa; plantillas de PDF por tipo de documento (bloques configurables, no HTML libre). Comprobantes y guías electrónicas usan el PDF de eFact (logo sincronizado); cotización, pedido, reserva, nota de venta, estado de cuenta, etiquetas y similares los genera el ERP.

## D18b. Confirmado: PDF de comprobantes y guías = PDF de eFact (2026-10-11)

## D19b. Navegador recomendado: Chrome (2026-10-11)
Se recomienda Chrome en todas las plataformas (se muestra aviso si se usa otro). Matiz técnico: en iOS todos los navegadores, incluido Chrome, usan el motor WebKit de Apple, por lo que las limitaciones de almacenamiento y sincronización en segundo plano son las mismas que en Safari. En iOS el sistema funciona en línea; el modo sin conexión (nivel 1) se declara **no garantizado**. Soporte pleno: Chrome en Android y escritorio.

## D19. Modo sin conexión por niveles
Nivel 0 (reintento seguro) en v1; nivel 1 (venta al contado de POS con cola local) en v1.5; niveles 2 y 3 fuera de alcance. Preparar desde v1: UUID v7, `Idempotency-Key`, lotes, series por equipo. Ver `investigacion/05-factibilidad-sin-conexion.md`.

## D20. Idioma, documentación y estándares
Código, tablas, columnas, rutas y códigos de error en **inglés**; documentación de código, comentarios de base de datos e interfaz en **español**. Todo elemento público documentado y verificado en CI. SOLID, arquitectura hexagonal, DDD ligero y pruebas por niveles. Ver `construccion/01-estandares-de-codigo.md`.

## D21. Base de datos: PostgreSQL
Motor único, con RLS como segunda barrera de aislamiento, particiones para libros y `COMMENT` obligatorio en tablas y columnas.

## D22. Ciclo de vida y borrado lógico
Auditoría estándar en toda tabla (`created_at/by`, `updated_at/by`, `deleted_at/by`, `version`). Maestros: `active`, `inactive`, `deleted` (este último no visible). Registros con historia no se eliminan: se **desactivan** o se **fusionan**; el borrado lógico es solo para los nunca usados. Documentos confirmados se anulan, los libros no se editan. Ver `03-modelo-de-datos.md` §18.

## D23. Redondeo y cálculo de importes = algoritmo de eFact
Un `TaxCalculator` replica el cálculo por línea de eFact (valor unitario a 10 decimales, IGV por diferencia, descuentos con factor a 6 decimales), con aritmética decimal y pruebas de oro. Ver `03-modelo-de-datos.md` §19.

## D24. Decisiones de la revisión de wireframes (2026-10-12)
1. El POS muestra **imagen del producto**.
2. **El vendedor crea el pedido.** El portal del cliente consulta y envía una solicitud ("Pedir de nuevo"); no crea pedidos.
3. El tablero de despacho usa las columnas **Confirmados · En despacho · Listos** (el estado interno `picking` se muestra como "En despacho").
4. El menú lateral usa **categorías con desplegables** (ver `04-flujos-y-pantallas.md` §1).
5. Rótulo de envío: definido (ver D26).

## D25. Frontend: tema como base, componentes generalizados y herramientas de diseño
`aura-admin-theme` es la base (tokens `oklch` con paleta cambiable por tono, kit Radix/shadcn, sidebar, tabla avanzada). Se retiran SSR y datos de demostración. Componentes compuestos genéricos (`ActionButton`, `FormDialog`, `DataTable`…) en `components/common`; la paleta por empresa se aplica con variables CSS. Las skills **ui-ux-pro-max** e **impeccable** se usan en pantallas de alto impacto y antes de cada hito. Ver `construccion/02-estandares-frontend.md`.

## D26. Rótulo, impresión por sucursal y comprobante de agencia (2026-10-12)
- Rótulo por bulto con destinatario (incluye DNI/RUC), destino, orden y código de la agencia, bulto "n/N", cobro en destino resaltado, QR de seguimiento y observaciones.
- El formato de impresión (ticket 58/80, A5, A4, etiquetas) es **configurable por sucursal y por tipo de documento**.
- El envío admite **subir el comprobante de la agencia** (foto o PDF); campos adicionales tomados de un ticket real: modalidad, fecha de traslado, orígen y destino con referencia, peso real y cobrado, garantía y forma de pago del flete.
- Lectura automática del QR o del texto del ticket: v1.5.

## D13. Onboarding y migración como parte del producto
Importación Excel/CSV con validación, saldos iniciales, datos demo, asistente por rubro y tutoriales dentro de la app. Es requisito de v1, no un extra.

## D7. Alcance de v1 (a validar en `01-alcance-mvp.md`)
Núcleo: empresas/sucursales/almacenes, usuarios y permisos, terceros, ítems, stock y kardex (stock opcional por ítem), ventas y POS, caja (apertura/arqueo/cierre), cobranzas (incluye crédito/fiado), compras, gastos, integración Gavi eFact (opcional), reportes simples.
Fuera de v1: contabilidad completa (solo reglas de asiento previstas en el modelo), planilla, producción, rubros especializados, multi-país.
