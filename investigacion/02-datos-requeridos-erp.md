# Datos requeridos para un ERP de ventas y facturación (Perú, extensible a otros países)

Fecha: 2026-10-11.
Fuentes: (a) esquema de tus 3 proyectos locales (`gavi-efact-v3` 54 tablas, `sistema_pro6` 292, `smart` 451); (b) conocimiento general de ERPs grandes (SAP Business One / S4, Odoo, ERPNext, NetSuite); (c) revisión de IntiFact (`01-intifact-revision-modulos.md`).
Los nombres de tablas son orientativos, no copia de ningún esquema. Los códigos de catálogo SUNAT hay que validarlos contra el anexo vigente antes de implementar.

## 1. Qué enseñan tus tres proyectos

| Proyecto | Tamaño | Lección |
|---|---|---|
| `gavi-efact-v3` | 54 tablas, Laravel 12 + Inertia/React, PostgreSQL, UUID | Diseño limpio: multi-tenant, roles por empresa, emisión SUNAT desacoplada de la venta, caja con arqueo por denominación, kardex con `stock_before/after`. Es la base natural. |
| `sistema_pro6` | 292 tablas, 38 módulos | Cubre todo el ciclo de un facturador: CPE, guías, retención/percepción, POS, restaurante, hotel, producción, e-commerce, SIRE, offline, planes/suscripción. |
| `smart` | 451 tablas, ~40 módulos | Suma contabilidad (libro diario, mayor, subdiarios), planilla, letras de cambio, préstamos bancarios, activos fijos, contratos, encuestas, óptica, canchas. |

**Patrones que sí repetir**
- Documento + ítems + pagos + cuotas (`documents`, `document_items`, `document_payments`, `document_fee`).
- Series por establecimiento y tipo de documento.
- Caja como sesión (apertura/cierre) y movimientos polimórficos hacia ventas, compras, gastos, ingresos.
- Kardex polimórfico (cada documento origen genera líneas) y stock por almacén (`item_warehouse`).
- Catálogos SUNAT en tablas `cat_*` (códigos cerrados) separados de datos del negocio.
- Plan/suscripción con límites (documentos/mes, usuarios, sucursales, productos).

**Errores que no repetir**
- `configurations` con **337 columnas** y `documents` con **133**: configuración y documento como "tabla ancha". Usar tabla clave/valor tipada (o JSON validado) para ajustes y separar totales, estado SUNAT y archivos en tablas propias.
- Snapshots en JSON (`customer`, `item`, `data_json`) sin esquema: guardar el snapshot **estructurado** (campos fijos de comprobante) y relacionar con maestros.
- Campos "por cliente" metidos en el core (`gekawa_*`, `optometry_*`, `canchas`): rubros deben ser módulos/extensiones, no columnas en el núcleo.
- Tablas duplicadas por flujo (`sale_notes` vs `documents`, `quotations`, `order_notes`, `contracts` casi iguales): un solo "documento comercial" con tipo y estado.
- Tenant mezclado con negocio (`website_id`, `company`, `alter_company` dentro de cada tabla): aislar con `tenant_id` + RLS.

## 2. Principios del modelo de datos

1. **Multi-tenant desde el día 1:** `tenant_id` (organización) → `company` (razón social/RUC) → `branch` (establecimiento/sucursal) → `warehouse`.
2. **Solo Perú en v1 (decisión D2, ver `diseno/00-decisiones-base.md`):** los catálogos SUNAT son nativos del modelo, aislados en un módulo `pe_*` para poder extraer una versión multi-país luego. La emisión se delega al SaaS Gavi eFact mediante un puerto/adaptador (D3). *(Antes se planteó un núcleo neutro con plugin por país; queda descartado para v1.)*
3. **Documento fiscal ≠ transacción:** la venta se confirma independiente de la emisión (cola, reintentos, estados SUNAT, CDR). Ya lo tienes en `gavi-efact-v3`.
4. **Inmutabilidad contable:** nada se borra; se anula o se revierte con nota/contra-asiento. Auditoría de quién/cuándo/IP.
5. **Moneda y decimales:** `numeric(18,6)` en cantidades/precios, `(18,2)` en importes; tipo de cambio por fecha.
6. **Todo con vigencia:** precios, tasas de impuesto y listas con `valid_from/valid_to`.
7. **Soft delete + estado explícito** en maestros; nunca borrar si tiene movimientos.
8. **Extensible:** campos personalizados por entidad y *rubros* como módulos activables por tenant.

## 3. Dominios y datos mínimos

### 3.1 Plataforma y seguridad
- `tenants`, `companies` (RUC, razón social, régimen tributario, ubigeo, entorno prod/beta, certificado, credenciales SOL/OSE cifradas), `branches` (código anexo SUNAT, ubigeo, dirección), `warehouses`.
- `users`, `roles`, `permissions` (ver/crear/editar/anular por módulo), `user_branches`, `api_keys`, `sessions/devices`.
- `plans`, `subscriptions`, `usage_counters` (documentos/mes), `feature_flags` por tenant.
- `audit_logs` (entidad, id, acción, antes/después, usuario, IP), `settings` (clave/valor tipada por company).

### 3.2 Maestros comerciales
- `parties` unificado (cliente, proveedor, transportista, empleado, banco): tipo doc identidad, número, nombre/razón social, condición SUNAT (activo/habido), agente de retención/percepción, correo, teléfono; `party_addresses` (ubigeo, tipo), `party_contacts`, `party_roles`.
- Cliente: lista de precios, días de crédito, línea de crédito, vendedor, zona, descuento, puntos.
- Proveedor: condiciones de pago, cuentas bancarias, retención/detracción.
- `price_lists`, `price_list_items`, `discounts`, `promotions`, `coupons`.
- `payment_terms` (contado, crédito N días, cuotas), `payment_methods` (efectivo, tarjeta, Yape, Plin, transferencia, etc.), `bank_accounts`.
- `currencies`, `exchange_rates` (fecha, compra, venta; fuente SUNAT/SBS).

### 3.3 Productos e inventario
- `items`: tipo (bien/servicio/kit/insumo), SKU, código de barras, nombre, descripción, categoría, marca, unidad base, código SUNAT (UNSPSC), afectación IGV venta/compra, ISC, ICBPER, detracción (código y %), estado, imágenes.
- `item_uoms` (presentaciones y factor de conversión), `item_variants` (talla/color/atributos), `item_sets/bom` (kits/recetas), `item_barcodes`.
- `item_prices` por lista/moneda/vigencia; `item_costs` (último, promedio, FIFO) con método por company.
- `warehouses`, `stock_balances` (item, almacén, cantidad, reservado, mínimo, máximo), `stock_ledger` (kardex valorizado: fecha, documento origen, entrada/salida, costo unitario, saldo).
- `lots/series` (lote, fecha vencimiento, serie/IMEI, garantía) y `stock_by_lot`.
- `transfers`, `adjustments` (conteo, merma, corrección), `inventory_counts`, `reservations`.
- Servicios: unidad de cobro, tiempo estimado, requiere materiales.

### 3.4 Ventas
- `sales_documents` (cotización, pedido, nota de venta, boleta, factura, nota crédito/débito) con: tipo, serie, número, fecha emisión/vencimiento, cliente, moneda, tipo de cambio, vendedor, sucursal, condición de pago, estado comercial, origen (POS, web, API).
- Totales separados: `total_taxed` (gravado), `total_exonerated`, `total_unaffected` (inafecto), `total_exportation`, `total_free` (gratuito), `igv`, `isc`, `icbper`, `descuento global`, `cargos`, `total`, `anticipos`.
- `sales_document_items`: ítem, descripción, cantidad, unidad, valor unitario, precio unitario, afectación IGV, descuento, impuestos, lote/serie, almacén.
- `sales_payments`, `sales_installments` (cuotas de crédito), `advances` (anticipos), `document_relations` (nota → factura, guía → factura).
- `detraction`, `retention`, `perception` por documento; `legends` (leyendas), `additional_info`.
- POS: `pos_terminals`, `cash_sessions`, `cash_movements`, `cash_counts` (arqueo por denominación), `shifts`.
- Cobranza: `receivables` (cuenta por cobrar), `receipts` (cobros aplicados a uno o varios documentos), `dunning` (recordatorios).
- Devoluciones: `returns`, `credit_notes` con motivo y restitución de stock.
- Comisiones de vendedor, metas, oportunidades (`opportunities`) si hay CRM.

### 3.5 Facturación electrónica (adaptador Perú)
- `fiscal_documents`: documento origen, tipo SUNAT, serie-número, estado (borrador, emitido, enviado, aceptado, aceptado con observación, rechazado, anulado, baja), hash, QR, nombres de XML/CDR/PDF, fecha envío, intentos, mensaje y código de respuesta.
- `fiscal_events` (historial de envíos, reintentos, errores) y `fiscal_queue`.
- `daily_summaries` (resumen diario de boletas), `voided_documents` (comunicación de baja), `ticket_status`.
- `dispatch_guides` (GRE remitente y transportista): motivo de traslado, modalidad, fecha, peso, bultos, puntos de partida/llegada (ubigeo), destinatario, transportista, vehículo, conductor, ítems, documentos relacionados.
- Compras/ventas SIRE: registros RVIE/RCE, propuesta vs. registrado.
- `carriers`, `drivers`, `vehicles` (placa, MTC, TUC).
- Certificado digital, credenciales SOL, tokens OSE/PSE (cifrados).

### 3.6 Compras y gastos
- `purchase_requests`, `rfqs` (cotización a varios proveedores), `purchase_orders`, `goods_receipts` (recepción parcial), `purchase_invoices` (registro de compras: tipo doc, serie, número, fecha, proveedor, base/IGV, crédito fiscal, detracción, retención, percepción), `supplier_payments`, `payables`.
- Importación: flete, seguro, aduana, prorrateo de costo (landed cost).
- `expenses` (categoría, comprobante, recurrente, aprobación), `budgets` por categoría/período.
- Servicios y activos: `fixed_assets` (alta, vida útil, depreciación, baja).

### 3.7 Finanzas y tesorería
- `bank_accounts`, `bank_movements`, `bank_reconciliations`, `checks`, `cash_flow`.
- `loans` (cronograma, cuotas), `bills_of_exchange` (letras: aceptación, protesto, descuento).
- `payments` y `receipts` con aplicación a documentos (conciliación N:N).

### 3.8 Contabilidad
- `chart_of_accounts` (PCGE por país, jerarquía, naturaleza, tipo, moneda, centro de costo), `accounting_periods` (abierto/cerrado).
- `journals/sub_diaries` (ventas, compras, caja, diario), `journal_entries` + `journal_lines` (debe/haber, moneda, TC, centro de costo, referencia a documento).
- `auto_accounting_rules` (cuenta por tipo de operación/ítem/categoría), `cost_centers`, `tax_codes`.
- Libros electrónicos (PLE) y SIRE: ventas, compras, diario, mayor, caja y bancos, inventario y balances; estados financieros (balance, resultados).
- Cierre mensual/anual, diferencia de cambio, revaluación, provisión.

### 3.9 RR.HH. y planilla (opcional, fase 4)
- `employees` (contrato, régimen, AFP/ONP, EsSalud, cargo, área), `contracts`, `attendance`, `payroll_runs`, `payroll_items` (conceptos remunerativos y descuentos), `pay_slips`, PLAME/T-Registro, CTS, gratificación, vacaciones.

### 3.10 Operaciones por rubro (módulos activables)
- Restaurante: mesas, comandas, cocina, meseros, propinas, recetas.
- Hotel: habitaciones, tarifas, reservas, check-in/out.
- Farmacia: DIGEMID, lote, registro sanitario, controlados.
- Taller/servicio técnico: órdenes de servicio, equipo, placa, estados, cotización, repuestos.
- Producción: órdenes, BOM, insumos, mermas, costos.
- Reparto/delivery: rutas, repartidores, estados de entrega, empaque.
- E-commerce: tienda, catálogo publicado, carritos, pedidos, pasarela de pago.
- Otros: contratos recurrentes (suscripciones), encuestas, óptica, canchas.

### 3.11 Integraciones y datos transversales
- Consulta RUC/DNI (SUNAT/RENIEC vía APIs), tipo de cambio, padrón SUNAT.
- WhatsApp/correo (envío de comprobantes), webhooks, API pública con claves.
- Notificaciones, archivos adjuntos, plantillas de impresión (A4, ticket 58/80), numeración de series.
- Offline/sincronización para POS: cola local, resolución de conflictos.

## 4. Catálogos SUNAT que debe traer el sistema

Verificar contra el anexo de SUNAT vigente antes de poblar:

| Catálogo | Contenido |
|---|---|
| 01 | Tipo de comprobante (01 factura, 03 boleta, 07 NC, 08 ND, 09 GRE remitente, 31 GRE transportista, 20 retención, 40 percepción) |
| 03 | Unidades de medida |
| 05 | Tipos de tributo (IGV, ISC, ICBPER, exonerado, inafecto, exportación, gratuito) |
| 06 | Tipo de documento de identidad (DNI, RUC, CE, pasaporte, sin documento) |
| 07 | Afectación IGV (gravado, exonerado, inafecto, exportación y sus variantes gratuitas) |
| 09 / 10 | Motivos de nota de crédito / débito |
| 16 | Tipo de precio (unitario, referencial) |
| 17 / 51 | Tipo de operación (venta interna, exportación, detracción, anticipos) |
| 18 / 20 | Modalidad (público/privado) y motivo de traslado |
| 25 | Código de producto SUNAT (obligatorio desde 2027) |
| 54 | Bienes y servicios sujetos a detracción y su porcentaje |
| 59 | Medios de pago |
| 52 / 53 | Leyendas, cargos y descuentos |
| Otros | Ubigeo (INEI), tipos de cambio, régimen de retención y percepción |

## 5. Núcleo mínimo para el MVP (alineado con el recorrido de IntiFact)

`tenants, companies, branches, warehouses, users, roles, permissions, parties(+addresses), items(+uoms, prices), stock_balances, stock_ledger, sales_documents(+items, payments, installments), series, fiscal_documents(+events), cash_sessions(+movements, counts), purchases(+items), suppliers, expenses, audit_logs, settings, plans, subscriptions` y los catálogos del punto 4.

Eso corresponde a ~35 tablas: el mismo orden de magnitud que `gavi-efact-v3` (54) pero sin sus 14 de infraestructura.

## 6. Qué decidir antes de modelar

1. `parties` unificado (recomendado) vs. clientes y proveedores separados.
2. Un solo "documento comercial" con tipo y estado vs. tablas por tipo.
3. Costo promedio ponderado vs. FIFO como método inicial (recomendado: promedio por almacén).
4. Contabilidad: asientos automáticos desde el día 1 (aunque la UI sea fase 3) vs. después. Recomendado: **preparar las reglas contables desde el modelo** para no reescribir.
5. UUID v7 como clave primaria (ordenable) en todo.
6. Particionar `stock_ledger`, `fiscal_events` y `audit_logs` por fecha desde el inicio.
