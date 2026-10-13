# Modelo de datos

Fecha: 2026-10-11. Estado: borrador para revisión. Base: `00-decisiones-base.md` (D1–D19), `01-alcance-mvp.md`, `02-arquitectura.md`.
Motor: PostgreSQL. Fuentes de contraste: `../investigacion/02-datos-requeridos-erp.md` y `03-gavi-efact-api-revision.md`.

## 0. Convenciones

| Tema | Regla |
|---|---|
| Nombres | Tablas y columnas en inglés `snake_case`, plural en tablas. Etiquetas de negocio, comentarios de base de datos (`COMMENT ON`) y textos de interfaz en español |
| Esquemas | `core` (plataforma), `cat` (catálogo), `inv`, `sales`, `pay`, `ship`, `buy`, `cash`, `fisc`, `notif`, `imp`, `pe` (catálogos SUNAT) |
| Claves | `id uuid` (v7). Claves de catálogo SUNAT = código SUNAT (`varchar`) |
| Aislamiento | Toda tabla de negocio lleva `tenant_id`. Donde aplica: `company_id`, `branch_id`, `warehouse_id` |
| Dinero | `numeric(14,2)`; precio unitario `numeric(14,6)`; valor unitario sin impuesto `numeric(18,10)`; cantidad `numeric(14,4)`; tasa `numeric(7,4)`. Cálculo según §19 |
| Moneda | `currency char(3)` (`PEN`, `USD`); tipo de cambio por documento |
| Fechas | `timestamptz` en UTC; fecha contable `date` en hora de Lima |
| Auditoría | Columnas de auditoría en **toda** tabla (ver §18.1), además de `audit_log` |
| Ciclo de vida | `status` (`active`, `inactive`, `deleted`) en maestros; borrado **lógico** siempre; reglas en §18 |
| Concurrencia | `version int` en documentos editables (bloqueo optimista) |
| Origen | Documentos con `origin` (`web`, `pos`, `portal`, `import`, `api`, `bot`), `device_id`, `local_number`, `client_created_at` (soporte para sin conexión) |
| Estados | `varchar` con `CHECK` y tabla de historial para los críticos |
| JSONB | Solo para configuración, plantillas y cargas crudas de integraciones; nunca para datos consultables |
| Instantáneas | Las líneas de documentos guardan copia de descripción, unidad, tasa y costo vigentes al emitir |

## 1. Vista general

```
core: tenant ─< company ─< branch ─< warehouse
        │          │
        │          ├─< document_series, price_lists, bank_accounts, payment_methods(activación), templates, fiscal_settings
        │          └─< (documentos de venta, compra, pedidos, pagos, caja)
        ├─< users ─< memberships(company, role)
        └─ parties, items(+variants), catálogos propios  (compartidos en la cuenta)

pedido → venta → envío
 quotations ─> orders ─< order_lines
                  │           │
                  │           ├──> stock_reservations
                  │           └──> sales_document_lines ──> sales_documents ──> fiscal_documents
                  └──> shipments ─< shipment_lines (a order_lines)
 pagos: payments ─< payment_allocations ──> (sales_documents | installments | orders | purchases)
 stock: stock_movements (ledger) ──> stock_balances (proyección)
```

## 2. Plataforma (`core`)

### 2.1 Cuenta, empresas y estructura
| Tabla | Columnas principales | Notas |
|---|---|---|
| `tenants` | id, name, status (`trial\|active\|suspended\|closed`), plan_id, trial_ends_at, owner_user_id, locale, timezone | La cuenta del cliente |
| `plans` | id, code, name, active | Plan interno del ERP |
| `plan_limits` | plan_id, key (`companies`, `branches`, `users`, `items`, `docs_month`…), value | Límites; `null` = ilimitado |
| `plan_features` | plan_id, feature (`cash`, `purchases`, `whatsapp`, `white_label`, `fiscal`…), enabled | Módulos por plan |
| `subscriptions` | id, tenant_id, plan_id, status, period_start, period_end, notes | v1: gestionada a mano |
| `subscription_payments` | id, subscription_id, amount, paid_at, method, reference, registered_by | Cobro manual del proveedor |
| `companies` | id, tenant_id, legal_name, trade_name, tax_id (RUC), tax_regime, igv_applies (bool), address, ubigeo, phone, email, base_currency, fiscal_mode (`none\|optional\|required`), logo_file_id, logo_dark_file_id, brand_color, status | Una por RUC. UNIQUE (tenant_id, tax_id) |
| `company_settings` | company_id, stock_control (bool), negative_stock_policy, cash_mode (`none\|session`), default_condition, price_includes_tax, rounding, JSONB `extra` | Una fila por empresa |
| `branches` | id, tenant_id, company_id, code (4 chars, `cod_local`), name, address, ubigeo, is_main, status | |
| `warehouses` | id, tenant_id, company_id, branch_id, name, kind (`sales\|storage\|transit`), allows_negative (nullable = política de empresa), status | |

### 2.2 Usuarios, roles y permisos
| Tabla | Columnas principales | Notas |
|---|---|---|
| `users` | id, tenant_id, name, email, phone, password_hash, pin_hash, status, mfa_enabled, last_login_at | Email único por cuenta |
| `memberships` | id, tenant_id, user_id, company_id, role_id, status | Usuario ↔ empresa ↔ perfil |
| `roles` | id, tenant_id, name, is_system, base_template | Perfiles por cuenta |
| `permissions` | code (`orders.order.cancel`), module, resource, action, description | Catálogo en código, sembrado |
| `role_permissions` | role_id, permission_code, effect (`allow\|deny`) | |
| `user_permission_overrides` | membership_id, permission_code, effect | Excepciones por usuario |
| `membership_scopes` | membership_id, scope_type (`branch\|warehouse\|cash_register`), scope_id | Alcance; vacío = todos |
| `role_limits` | role_id, key (`max_discount_pct`, `max_void_amount`…), value, requires_approval_above | Límites numéricos |
| `approvals` | id, tenant_id, requested_by, approved_by, action, subject_type, subject_id, reason, status, decided_at | Autorizaciones de supervisor |

### 2.3 Transversales
| Tabla | Columnas principales | Notas |
|---|---|---|
| `files` | id, tenant_id, company_id, kind, storage_key, mime, size, sha256, owner_type, owner_id | Todos los archivos |
| `audit_log` | id, tenant_id, company_id, actor_type, actor_id, action, subject_type, subject_id, before JSONB, after JSONB, ip, request_id, at | Partición mensual; inmutable |
| `idempotency_keys` | tenant_id, key, scope, request_hash, response_ref, status, created_at | UNIQUE (tenant_id, scope, key) |
| `domain_events` | id, tenant_id, type, payload JSONB, occurred_at, published_at, attempts | Outbox de eventos |
| `document_series` | id, tenant_id, company_id, branch_id, doc_type, series, next_number, is_fiscal, efact_synced_at, device_id (nullable), active | Numeración. Fiscales: espejo del correlativo de eFact; internas: numeración propia |
| `document_templates` | id, tenant_id, company_id, doc_type, format, config JSONB, version, is_default, active | Plantillas de PDF |
| `document_template_versions` | template_id, version, config JSONB, created_at, created_by | Historial para reimprimir |
| `branch_print_settings` | id, tenant_id, company_id, branch_id, doc_type, format (`ticket_58\|ticket_80\|a5\|a4\|label_100x150\|a6\|label_a4_4up\|product_40x25`), copies, auto_print, template_id (nullable) | Formato de impresión por sucursal y tipo de documento. UNIQUE (branch_id, doc_type) |
| `exchange_rates` | date, currency, buy, sell, source | Tipo de cambio |

## 3. Catálogo (`cat`)

### 3.1 Terceros
| Tabla | Columnas principales | Notas |
|---|---|---|
| `parties` | id, tenant_id, is_customer, is_supplier, person_type (`natural\|legal`), id_doc_type (Cat 06), id_doc_number, legal_name, trade_name, email, phone, whatsapp, status, restricted_to_companies (bool) | UNIQUE (tenant_id, id_doc_type, id_doc_number) cuando hay documento. Un solo maestro |
| `party_addresses` | id, party_id, label, address, ubigeo, is_fiscal, is_delivery, reference | Varias direcciones |
| `party_contacts` | id, party_id, name, role, phone, email | |
| `party_companies` | party_id, company_id | Solo si `restricted_to_companies` |
| `party_company_terms` | party_id, company_id, credit_limit, payment_term_days, price_list_id, seller_user_id, default_carrier_id, notes | Condiciones por empresa |
| `party_consents` | party_id, channel, purpose, granted_at, revoked_at, source | Consentimiento de notificaciones |

### 3.2 Ítems, atributos y variantes
**Decisión:** todo ítem tiene **al menos una variante** (la "predeterminada"). Pedidos, ventas, stock y precios referencian siempre `variant_id`. Así un producto sin variantes y uno con talla/color usan el mismo flujo.

| Tabla | Columnas principales | Notas |
|---|---|---|
| `categories` | id, tenant_id, parent_id, name, path, sort | Árbol |
| `brands` | id, tenant_id, name | |
| `items` | id, tenant_id, kind (`good\|service`), code, name, description, category_id, brand_id, base_uom (Cat 03), tracks_stock (bool), has_variants (bool), default_tax_affectation (Cat 07, nullable = según empresa), unspsc_code, image_file_id, status | Servicio: `tracks_stock=false`, UM por defecto `ZZ`; bien: `NIU` |
| `attributes` | id, tenant_id, name (`Talla`, `Color`…), kind (`list\|text`) | Definidos por el negocio |
| `attribute_values` | id, attribute_id, value, sort, color_hex | |
| `item_attributes` | item_id, attribute_id, sort | Atributos que usa el ítem |
| `item_variants` | id, tenant_id, item_id, sku, name, is_default, cost_reference, weight, status | |
| `variant_attribute_values` | variant_id, attribute_id, attribute_value_id | |
| `item_packagings` | id, variant_id, uom (Cat 03), label (`Docena`), factor_to_base, is_sale_unit, is_purchase_unit | Conversión de unidades |
| `item_barcodes` | id, tenant_id, variant_id, packaging_id (nullable), code, kind (`ean13\|code128\|internal`) | UNIQUE (tenant_id, code) |
| `item_companies` | item_id, company_id, enabled, tax_affectation_override, isc, icbper | Habilitación por empresa |
| `item_images` | id, item_id, variant_id, file_id, sort | |

### 3.3 Precios
| Tabla | Columnas principales | Notas |
|---|---|---|
| `price_lists` | id, tenant_id, company_id, name, currency, includes_tax, is_default, valid_from, valid_to | |
| `price_list_items` | id, price_list_id, variant_id, packaging_id (nullable), min_qty, price | Escalas: varias filas por variante con `min_qty` creciente |
| `price_overrides` | id, company_id, party_id, variant_id, price | Precio pactado por cliente |

Resolución de precio (orden): precio pactado → lista del cliente (con escala) → lista predeterminada → costo + margen (opcional).

## 4. Inventario (`inv`)

| Tabla | Columnas principales | Notas |
|---|---|---|
| `stock_movements` | id, tenant_id, company_id, warehouse_id, variant_id, movement_type, direction (`in\|out`), qty (base), unit_cost, total_cost, source_type, source_id, source_line_id, occurred_at, posted_at, balance_after, created_by | **Append-only**, partición por mes. Nunca se edita; se corrige con otro movimiento |
| `stock_balances` | tenant_id, warehouse_id, variant_id, on_hand, reserved, avg_cost, last_movement_at | Proyección. PK (warehouse_id, variant_id). `available = on_hand - reserved` |
| `stock_reservations` | id, tenant_id, warehouse_id, variant_id, order_line_id, qty, status (`active\|released\|consumed`), expires_at | Reserva por línea de pedido |
| `inventory_documents` | id, tenant_id, company_id, type (`adjustment\|transfer\|count\|opening`), number, status, from_warehouse_id, to_warehouse_id, reason, notes, posted_at | Ajustes, traslados, toma física, saldo inicial |
| `inventory_document_lines` | id, document_id, variant_id, qty, counted_qty, system_qty, unit_cost, reason | |
| `stock_alerts` | company_id, warehouse_id, variant_id, min_qty, reorder_qty | Mínimos |

Tipos de movimiento: `sale`, `sale_return`, `purchase`, `purchase_return`, `adjustment_in`, `adjustment_out`, `transfer_out`, `transfer_in`, `opening`, `count_diff`, `production` (reservado).
Traslado entre sucursales: salida a almacén en tránsito, luego entrada en destino. Entre empresas: venta/compra.
Reservados para después, sin tabla aún: `lots`, `serial_numbers` (columnas `lot_id`, `serial_id` nulas en movimientos desde el inicio).

## 5. Pedidos (`sales`)

| Tabla | Columnas principales | Notas |
|---|---|---|
| `quotations` | id, tenant_id, company_id, branch_id, number, party_id, seller_id, date, valid_until, currency, status, totals…, terms, notes, converted_order_id | |
| `quotation_lines` | id, quotation_id, variant_id, packaging_id, description, qty, unit_price, discount, tax_affectation, line_total | |
| `orders` | id, tenant_id, company_id, branch_id, number, party_id, seller_id, source (`whatsapp\|counter\|portal\|phone\|import`), quotation_id, status, currency, totals…, delivery_type (`pickup\|shipment\|delivery`), delivery_address_id, requested_date, prepaid_amount, notes, public_token | Pedido/reserva. `public_token` para seguimiento |
| `order_lines` | id, order_id, variant_id, packaging_id, description, qty_ordered, qty_reserved, qty_picked, qty_shipped, qty_invoiced, unit_price, discount, tax_affectation, line_total, warehouse_id, note | Cantidades por etapa |
| `order_status_history` | id, order_id, from_status, to_status, reason, by_user, at | |
| `picking_lists` | id, order_id, status, assigned_to, packed_at | Armado |
| `picking_packages` | id, picking_list_id, label, weight, notes | Bultos armados |

Estados de pedido: `draft → confirmed → picking → ready → partially_shipped → shipped → delivered | cancelled`.

## 6. Ventas (`sales`)

### 6.1 Documento de venta
Un solo documento comercial con tipo y estado.

| Tabla | Columnas principales | Notas |
|---|---|---|
| `sales_documents` | id, tenant_id, company_id, branch_id, doc_type (`NV`, `01`, `03`, `07`, `08`), series, number, party_id, order_id (nullable), cash_session_id, seller_id, issue_date, due_date, condition (`cash\|credit`), currency, exchange_rate, price_includes_tax, subtotal_taxable, subtotal_exempt, subtotal_unaffected, subtotal_export, subtotal_free, global_discount, igv_amount, isc_amount, icbper_amount, other_charges, total, paid_amount, balance_due, status (`draft\|confirmed\|voided`), origin, device_id, local_number, converted_from_id, fiscal_status (proyección), detraction_applies, notes | `balance_due` mantenido transaccionalmente. UNIQUE (company_id, doc_type, series, number) |
| `sales_document_lines` | id, document_id, order_line_id, variant_id, packaging_id, description, uom, qty, unit_price, unit_value (sin impuesto, 10 decimales), price_includes_tax, discount_amount, discount_pct, tax_affectation (Cat 07), igv_rate, igv_amount, isc_amount, icbper_amount, unit_cost_snapshot, line_total, warehouse_id | Copia de datos al emitir |
| `sales_document_installments` | id, document_id, number, due_date, amount, paid_amount, status | Cuotas de crédito |
| `sales_document_references` | id, document_id, affected_document_id, reason_code (Cat 09/10), reason_text | Para NC y ND |
| `sales_document_detraction` | document_id, good_code (Cat 54), pct, amount, account, payment_means | Cuando aplica |
| `sales_document_status_history` | id, document_id, from_status, to_status, reason, by_user, at | |

### 6.2 Reglas
- `NV` (nota de venta) no pasa por `FiscalEngine`; puede convertirse en boleta o factura creando un documento nuevo con `converted_from_id` y marcando la nota original como convertida.
- NC/ND exigen `sales_document_references` y líneas propias.
- La anulación de un comprobante aceptado sigue la máquina de estados fiscal (resumen/baja/NC); la nota de venta se anula localmente.
- El stock se mueve al **confirmar**, no al emitir el comprobante (salvo política contraria de la empresa).
- Nada se borra: un documento confirmado solo se anula.

## 7. Pagos y cuentas (`pay`)

| Tabla | Columnas principales | Notas |
|---|---|---|
| `payment_methods` | id, tenant_id, code, name, kind (`cash\|digital_wallet\|card\|transfer\|deposit\|check\|other`), requires_reference, active | Efectivo, Yape, Plin, tarjeta, transferencia… |
| `company_payment_methods` | company_id, payment_method_id, bank_account_id, enabled | Activación por empresa |
| `bank_accounts` | id, tenant_id, company_id, bank, account_number, cci, currency, holder, kind (`bank\|wallet\|cash`) | También sirven para la sección bancaria del PDF |
| `payments` | id, tenant_id, company_id, branch_id, direction (`in\|out`), party_id, payment_method_id, bank_account_id, amount, currency, exchange_rate, paid_at, reference, cash_session_id, status (`posted\|voided`), unapplied_amount, origin, device_id, notes | Dinero real recibido o entregado |
| `payment_allocations` | id, payment_id, target_type (`sales_document\|installment\|order\|purchase_document\|expense`), target_id, amount | A qué se aplica. Pago mixto = varios pagos; un pago puede cubrir varios documentos |
| `credit_applications` | id, credit_note_id, target_document_id, amount | Aplicar NC al saldo de otro documento |
| `receivable_summary` (vista) | company_id, party_id, due_total, overdue_total, oldest_due_date | CxC derivada |
| `payable_summary` (vista) | company_id, supplier_id, … | CxP derivada |

Reglas:
- Anticipo de pedido: `payment` + `payment_allocation (target_type='order')`; al facturar, la asignación se traslada al documento.
- `unapplied_amount` representa saldo a favor del cliente.
- Anular un pago revierte asignaciones y recalcula `balance_due`.
- CxC y CxP **no** son tablas editables: se derivan de documentos, cuotas y asignaciones.

## 8. Caja (`cash`)

| Tabla | Columnas principales | Notas |
|---|---|---|
| `cash_registers` | id, tenant_id, company_id, branch_id, name, requires_session (bool), active, device_id | Si `requires_session=false`, no hay apertura |
| `cash_sessions` | id, cash_register_id, opened_by, opened_at, opening_amount, closed_by, closed_at, expected_amount, counted_amount, difference, count_mode (`denomination\|total`), status | Turno |
| `cash_session_counts` | session_id, phase (`open\|close`), denomination (0.10…200.00), quantity, amount | Conteo por denominación PEN |
| `cash_movements` | id, cash_session_id, cash_register_id, kind (`in\|out`), reason, amount, payment_id (nullable), notes, by_user, at | Ingresos y egresos de caja chica |

Efectivo esperado = apertura + cobros en efectivo del turno − pagos en efectivo + ingresos − egresos.

## 9. Envíos (`ship`)

| Tabla | Columnas principales | Notas |
|---|---|---|
| `carriers` | id, tenant_id, name, kind (`agency\|courier\|own\|pickup`), tracking_url_template, phone, notes, active | Shalom, Olva, Marvisur, propio… |
| `carrier_offices` | id, carrier_id, name, address, ubigeo, schedule | Agencias de destino |
| `shipments` | id, tenant_id, company_id, branch_id, number, order_id, sales_document_id (nullable), carrier_id, service_mode (`land\|air\|other`), origin_office_id, destination_office_id, destination_address, destination_reference, destination_ubigeo, delivery_mode (`agency_pickup\|home_delivery`), recipient_name, recipient_doc_type, recipient_doc, recipient_phone, carrier_order_number, pickup_code, carrier_issued_at, transfer_date, grr_number, freight_amount, freight_payer (`sender\|recipient`), freight_status (`prepaid\|pending\|collect_on_delivery`), cod_amount, cod_collected_amount, packages_count, declared_weight, charged_weight, content_description, has_insurance, observations, status, shipped_at, delivered_at, public_token | Campos derivados de un ticket real de agencia. `carrier_order_number` y `pickup_code` equivalen a "NRO. ORDEN" y "CÓDIGO"; `cod_*` = cobro en destino |
| `shipment_attachments` | id, shipment_id, file_id, kind (`carrier_ticket\|delivery_proof\|other`), notes, uploaded_by, uploaded_at | Comprobante de la agencia (foto o PDF), cargo de entrega y otros |
| `shipment_labels` | id, shipment_id, package_id, format, printed_at, printed_by, copies | Registro de rótulos impresos (reimpresión controlada) |
| `shipment_lines` | id, shipment_id, order_line_id, variant_id, qty | Entregas parciales |
| `shipment_packages` | id, shipment_id, label, weight, picking_package_id | Bultos |
| `shipment_events` | id, shipment_id, status, note, occurred_at, by_user, source (`manual\|carrier\|webhook`), file_id | Línea de tiempo |
| `dispatch_guides` | id, shipment_id, fiscal_document_id, guide_type (`GRR\|GRT`), transfer_reason (Cat 20), transport_mode (Cat 18), transporter_id, vehicle, driver | Guía de remisión opcional |

Estados de envío: `pending → dispatched → in_transit → at_destination_office → delivered | returned | incident`.
Todos los comprobantes de agencia se guardan en `files` y se enlazan por `shipment_attachments`. La lectura automática del QR o del texto queda para después (v1.5).

## 10. Compras y gastos (`buy`)

| Tabla | Columnas principales | Notas |
|---|---|---|
| `purchase_documents` | id, tenant_id, company_id, branch_id, supplier_id, doc_type (Cat 01), series, number, issue_date, due_date, condition, currency, exchange_rate, subtotals…, igv_amount, total, paid_amount, balance_due, status, warehouse_id, notes, file_id | Compra registrada |
| `purchase_document_lines` | id, document_id, variant_id, description, uom, qty, unit_cost, tax_affectation, igv_amount, line_total | |
| `purchase_installments` | id, document_id, number, due_date, amount, paid_amount, status | |
| `expense_categories` | id, tenant_id, company_id, name, parent_id | |
| `expenses` | id, tenant_id, company_id, branch_id, category_id, supplier_id, doc_type, series, number, date, amount, tax_amount, paid_amount, status, payment_id, cash_session_id, notes | Gastos sin inventario |
| `purchase_orders` (después) | — | Orden de compra |

## 11. Fiscal (`fisc` y `pe`)

### 11.1 Catálogos SUNAT (`pe`, clave = código SUNAT)
| Tabla | Catálogo | Contenido |
|---|---|---|
| `pe.document_types` | 01 | 01, 03, 07, 08, 09, 31… |
| `pe.uoms` | 03 | NIU, ZZ, KGM, MTR, LTR… |
| `pe.id_doc_types` | 06 | DNI, RUC, CE, pasaporte… |
| `pe.tax_affectations` | 07 | 10 gravado, 20 exonerado, 30 inafecto, 40 exportación, 11–17, 21, 31–37 gratuitas; con `tax_group` y `is_free` |
| `pe.credit_note_reasons` | 09 | |
| `pe.debit_note_reasons` | 10 | incluye 13 desde 2027-01-01 |
| `pe.transfer_reasons` | 20 | Motivo de traslado |
| `pe.transport_modes` | 18 | |
| `pe.detraction_goods` | 54 | Bienes y servicios sujetos a detracción |
| `pe.unspsc` | 25 | Código de producto |
| `pe.ubigeo` | INEI | departamento, provincia, distrito |
| `pe.tax_rates` | — | IGV, ISC, ICBPER, con vigencia (`valid_from`, `valid_to`) |
| `pe.tax_regimes` | — | RG, RMT, RER, NRUS, con reglas por defecto de afectación |

### 11.2 Operación fiscal
| Tabla | Columnas principales | Notas |
|---|---|---|
| `fisc.company_settings` | company_id, engine (`gavi_efact`), environment (`beta\|production`), remote_tenant_id, api_key_enc, api_secret_enc, webhook_secret_enc, plan_slug, quota_month, quota_used, quota_checked_at, last_health_at, status | Credenciales de eFact **cifradas**. La clave SOL y el certificado se envían en el aprovisionamiento y **no se guardan** en el ERP. UNIQUE (company_id) |
| `fisc.documents` | id, tenant_id, company_id, sales_document_id (o shipment/dispatch), doc_type, external_id (= id local), remote_id, series, number, state (`pending\|sending\|sent\|accepted\|rejected\|observed\|voiding\|voided`), sunat_code, sunat_message, amounts_remote JSONB, hash, xml_file_id, cdr_file_id, pdf_file_id, attempts, next_retry_at, last_error, sent_at, accepted_at | UNIQUE (company_id, external_id). `external_id` evita duplicados |
| `fisc.events` | id, fiscal_document_id, kind (`request\|response\|webhook\|retry\|poll\|void`), status_code, payload JSONB, at | Partición mensual; auditoría |
| `fisc.webhook_inbox` | event_id (UNIQUE), company_id, signature_ok, payload JSONB, received_at, processed_at | Deduplicación por `X-Gavi-Event-Id` |
| `fisc.reconciliation_runs` | id, company_id, started_at, finished_at, checked, mismatches | Reconciliador diario |
| `fisc.void_requests` | id, fiscal_document_id, method (`summary\|void_notice\|credit_note`), state, ticket, requested_by, reason | Anulación asimétrica |

Reglas:
- `fisc.documents.state` es la **fuente**; `sales_documents.fiscal_status` es proyección para listas.
- Se guardan los montos que devuelve eFact y se alerta si difieren de los locales.
- El correlativo definitivo llega en la respuesta; `document_series.next_number` es solo espejo.
- El PDF del comprobante es el de eFact; se copia a `files`.

## 12. Notificaciones y portal (`notif`)

| Tabla | Columnas principales | Notas |
|---|---|---|
| `notification_templates` | id, tenant_id, company_id (nullable), event, channel, language, body, variables JSONB, active | Plantillas con variables (`{cliente}`, `{pedido}`, `{estado}`) |
| `notification_rules` | id, tenant_id, company_id, event, audience (`customer\|owner\|provider`), channel, template_id, enabled, quiet_hours | Qué evento notifica a quién |
| `notifications` | id, tenant_id, company_id, audience, party_id, user_id, channel, template_id, payload JSONB, status (`queued\|sent\|delivered\|failed\|skipped`), attempts, provider_message_id, error, queued_at, sent_at | Outbox y bitácora |
| `channel_accounts` | id, tenant_id, channel, provider (`evolution`), instance, status, rate_limit, config_enc | Cuenta de WhatsApp del sistema |
| `portal_links` | id, tenant_id, party_id, token_hash, scope, expires_at, last_used_at, revoked_at | Enlace mágico |
| `portal_accesses` | id, portal_link_id, ip, user_agent, at | |

## 13. Importación (`imp`)

| Tabla | Columnas principales | Notas |
|---|---|---|
| `import_batches` | id, tenant_id, company_id, entity, file_id, status (`uploaded\|validated\|processing\|done\|failed\|reverted`), total, ok, errors, is_demo, created_by | |
| `import_rows` | id, batch_id, row_number, raw JSONB, status, errors JSONB, created_ref_type, created_ref_id | |
| (columna) `import_batch_id` | en `items`, `parties`, `stock_movements`, `sales_documents` | Para revertir un lote y borrar los datos demo |

## 14. Reglas de integridad críticas
1. `stock_movements` no se actualiza ni se borra. Los saldos se pueden reconstruir.
2. `stock_balances.reserved` = suma de reservas activas.
3. Toda línea de venta, pedido y movimiento referencia `variant_id` y una unidad con factor definido.
4. `balance_due = total - paid_amount` en documentos, y `paid_amount` = suma de asignaciones no anuladas.
5. Un pedido no puede tener `qty_shipped > qty_ordered` ni `qty_invoiced > qty_shipped` salvo facturación anticipada configurada.
6. Un `sales_document` fiscal confirmado no se edita; se anula o se corrige con NC/ND.
7. Un tercero no se borra si tiene documentos; se archiva.
8. El `tenant_id` de una fila debe coincidir con el de sus padres (verificado por clave compuesta o *trigger*).
9. Una empresa con `fiscal_mode='none'` no puede crear `fisc.documents`.
10. Una serie fiscal no se reusa ni se renumera.

## 15. Índices y particiones (inicial)
| Tabla | Índices clave | Partición |
|---|---|---|
| `stock_movements` | (tenant_id, warehouse_id, variant_id, occurred_at), (source_type, source_id) | mensual |
| `sales_documents` | (company_id, issue_date), (company_id, party_id, status), único de serie y número | opcional por año |
| `payments` | (company_id, paid_at), (party_id) | — |
| `item_barcodes` | único (tenant_id, code) | — |
| `items` / `parties` | trigram sobre nombre y documento para búsqueda | — |
| `fisc.events`, `audit_log`, `notifications` | (tenant_id, at) | mensual |

## 16. Migración y siembra
- Orden de migraciones: `pe` (catálogos) → `core` → `cat` → `inv` → `pay` → `sales` → `cash` → `ship` → `buy` → `fisc` → `notif` → `imp`.
- Siembra: catálogos SUNAT, permisos, planes, perfiles plantilla, unidades, medios de pago, tipos de movimiento.
- Datos demo por rubro como lote de importación marcado.

## 17. Decisiones abiertas del modelo
1. **Costeo:** promedio ponderado por almacén en v1; FIFO por capas queda para después.
2. ~~Redondeo~~ **Resuelto:** se replica el algoritmo de eFact, por línea (ver §19).
3. **Bonificaciones y obsequios:** tratamiento con afectaciones gratuitas (11–17) y su efecto en stock y totales.
4. **Pedido ↔ venta:** una venta puede cubrir varios pedidos y un pedido varias ventas; hoy la unión es por línea (`order_line_id`).
5. **Moneda de caja:** una moneda por caja en v1.
6. **Series por equipo** para sin conexión: tabla preparada, activación en v1.5.
7. **Compras:** orden de compra y recepción separadas de la factura de compra, para después.
8. **Notas de crédito sobre documentos antiguos** (> 1 año) y cuentas por cobrar vencidas: política por definir.
9. **Cobranza de retención sufrida:** modelar como medio de pago "retención" con comprobante asociado.
10. **Impuestos en servicios:** detracción obligatoria sobre S/ 700 con cuenta del proveedor (campo ya previsto).


## 18. Ciclo de vida, auditoría y borrado lógico

### 18.1 Columnas de auditoría estándar
| Columna | Tipo | Se aplica a | Quién la llena |
|---|---|---|---|
| `created_at` | timestamptz not null | todas las tablas | Aplicación (y `default now()`) |
| `created_by` | uuid → users, nullable | todas | Aplicación; `null` para sistema, portal, bot o importación (el canal queda en `origin`) |
| `updated_at` | timestamptz not null | tablas modificables | Aplicación y disparador de respaldo |
| `updated_by` | uuid → users, nullable | tablas modificables | Aplicación |
| `deleted_at` | timestamptz, nullable | tablas con borrado lógico | Aplicación |
| `deleted_by` | uuid → users, nullable | ídem | Aplicación |
| `deletion_reason` | text, nullable | ídem | Aplicación (obligatorio en documentos) |
| `version` | int not null default 1 | documentos y maestros editables | Aplicación (bloqueo optimista) |

- Tablas **append-only** (`stock_movements`, `audit_log`, `fisc.events`, `shipment_events`, `payment_allocations`): solo `created_at` y `created_by`; no tienen `updated_*` ni `deleted_*`.
- El detalle de **qué** cambió vive en `audit_log` (antes/después). Las columnas responden "quién creó y quién tocó por última vez" sin consultar el registro.
- Implementación: *trait* de modelo + *observer* que completa los campos desde el actor de la solicitud; un disparador de base de datos asegura `updated_at`. Ninguna regla de negocio escribe estas columnas a mano.

### 18.2 Tres familias de registros
| Familia | Ejemplos | Estados | "Eliminar" significa |
|---|---|---|---|
| **Maestros** | terceros, ítems, variantes, categorías, marcas, almacenes, sucursales, agencias, medios de pago, usuarios, perfiles, atributos, listas de precios | `active`, `inactive`, `deleted` | Ver §18.3 |
| **Documentos** | cotizaciones, pedidos, ventas, compras, pagos, envíos, documentos de inventario | Estados de flujo propios (`draft`, `confirmed`, `voided`…) | Borrador: borrado lógico. Confirmado: **anular** con motivo |
| **Libros** (append-only) | movimientos de stock, auditoría, eventos fiscales, líneas de tiempo | Sin estado de vida | Nunca se borran ni editan; se corrigen con un contra-asiento |

### 18.3 Estados de los maestros
| Estado | Significado | Visible en la aplicación | Uso en nuevas operaciones |
|---|---|---|---|
| `active` | Vigente | Sí | Sí |
| `inactive` | Dado de baja de forma reversible | Sí, con etiqueta y filtro "Mostrar inactivos"; en históricos y reportes siempre | **No** (no aparece en buscadores ni en el POS) |
| `deleted` | Eliminación lógica | **No** (ni en listas ni en búsquedas); solo super-admin y tareas de limpieza | No |

Restricciones únicas del negocio (documento de identidad, SKU, código de barras, código de ítem) son **índices parciales** `WHERE status <> 'deleted'`: un registro eliminado libera su clave; uno inactivo la conserva.

### 18.4 Qué pasa al eliminar algo que ya tiene relaciones
**Recomendación: no existe "eliminar" para un registro con historia; existe "desactivar".** El borrado queda solo para registros que nunca se usaron.

Flujo único para todos los maestros (guardia de eliminación):
1. El usuario pulsa **Eliminar**.
2. El sistema consulta las referencias del registro (pedidos, ventas, pagos, movimientos, saldos, series, usuarios asignados…).
3. **Sin referencias:** se pide confirmación y pasa a `deleted` (lógico). Un administrador puede restaurarlo durante la ventana de retención.
4. **Con referencias:** no se permite eliminar. El diálogo muestra el porqué ("tiene 14 pedidos, 3 ventas y S/ 420.00 por cobrar") y ofrece:
   - **Desactivar** (recomendado): desaparece de buscadores y de nuevas operaciones, permanece en el historial.
   - **Fusionar con otro registro** (terceros e ítems): para duplicados. Mueve todas las relaciones al registro que se conserva; el duplicado queda `deleted` con `merged_into_id`.
5. Registros con saldo o trabajo en curso exigen resolverlo antes de desactivar (ver tabla).

| Entidad | Desactivar si… | Eliminar solo si… |
|---|---|---|
| Tercero | No tiene pedidos o ventas abiertos. Con saldo por cobrar/pagar: se permite con aviso, la deuda no se pierde | Nunca fue usado en un documento |
| Ítem / variante | Siempre; aviso si hay stock o reservas | Sin movimientos, sin documentos, sin reservas |
| Almacén | Stock en cero y sin reservas | Sin movimientos |
| Sucursal | Sin almacenes activos ni turnos abiertos | Sin documentos |
| Empresa | Sin documentos fiscales pendientes y sin cupo en uso; solo el proveedor lo ejecuta | Sin documentos |
| Usuario | Siempre (conserva la autoría); cierra sesiones | Sin acciones registradas |
| Perfil (rol) | Sin usuarios asignados | Sin usuarios asignados |
| Medio de pago, agencia, categoría, marca, atributo | Siempre | Sin uso |
| Serie fiscal | Sin documentos pendientes; **nunca** se elimina ni reutiliza | No aplica |

### 18.5 Documentos
- **Borrador:** se puede eliminar (lógico, con motivo). Libera reservas.
- **Confirmado:** no se elimina; se **anula** (`voided`) con motivo y autorización según el perfil. La anulación genera los contra-movimientos de stock, pagos y asignaciones y, si hay comprobante, sigue el flujo fiscal (resumen, baja o NC).
- Los números de documento consumidos **no se reutilizan**.
- Documentos y movimientos se conservan por obligación tributaria (plazo a confirmar con un contador).

### 18.6 Datos personales y supresión (Ley 29733)
- Un tercero con solicitud de supresión se **anonimiza** (`anonymized_at`): se reemplazan nombre, documento, teléfono, correo y direcciones por valores neutros. Los documentos fiscales conservan la **instantánea** de los datos que tenían al emitirse.
- La anonimización la ejecuta un administrador con motivo registrado; no es reversible.
- Validar con asesoría legal el alcance exacto antes de producción.

### 18.7 Limpieza (a definir)
- Una tarea programada purga físicamente solo registros `deleted` **sin referencias** y con antigüedad superior a la retención configurada (propuesta: 90 días); nunca documentos ni libros.
- Cierre de cuenta: exportación completa y eliminación diferida con plazo.
- Las claves foráneas usan `ON DELETE RESTRICT`: como segunda barrera, un borrado físico indebido falla en la base de datos.

### 18.8 Estructura técnica del borrado
- Contrato `DeletionGuard` por entidad (patrón estrategia): cada módulo registra comprobadores de referencias; añadir una entidad no exige modificar el servicio común.
- Caso de uso común `RemoveRecord`: decide entre eliminar, desactivar o rechazar, y devuelve los motivos con el diccionario de errores.
- *Global scope* que excluye `deleted` por defecto; solo el super-admin lo desactiva de forma explícita.

### 18.9 Matriz completa por dominio
La política de §18.4 aplica a **todos** los dominios. `D` = desactivar, `X` = eliminar lógico, `A` = anular, `F` = fusionar. Regla común: **X solo si nunca se usó**; con historia solo D (maestros) o A (documentos).

#### Catálogo
| Entidad | Tipo | Desactivar (D) cuando… | Eliminar (X) solo si… | Notas |
|---|---|---|---|---|
| Tercero (cliente/proveedor) | Maestro | Siempre; aviso si hay saldo o pedidos abiertos | Sin documentos | Admite **F** y anonimización |
| Dirección / contacto de tercero | Maestro hijo | Siempre | Sin envíos ni documentos que la usen | Los documentos guardan copia de la dirección |
| **Ítem (producto o servicio)** | Maestro | Siempre; aviso si hay stock, reservas o pedidos abiertos | Sin movimientos, sin documentos y sin reservas | Admite **F**. Desactivar un ítem desactiva sus variantes |
| **Variante** | Maestro | Siempre; aviso si hay stock | Sin movimientos ni documentos | No puede quedar un ítem activo sin variantes activas |
| Empaque/presentación | Maestro hijo | Siempre | Sin documentos que lo usen | La unidad base no se elimina |
| **Código de barras** | Maestro hijo | Se libera al eliminar, se conserva al desactivar | Siempre que no sea el único del ítem | No se reasigna silenciosamente: se muestra a qué ítem pertenece |
| Categoría | Maestro | Siempre; sus ítems siguen usándola | Sin ítems ni subcategorías | Alternativa: **mover** ítems a otra categoría |
| Marca | Maestro | Siempre | Sin ítems | Admite **F** |
| Atributo (Talla, Color…) | Maestro | Siempre | Sin ítems que lo usen | |
| Valor de atributo | Maestro hijo | Siempre | Sin variantes que lo usen | Con uso: solo D; las variantes conservan su valor |
| Lista de precios | Maestro | No puede ser la predeterminada ni estar asignada a un cliente | Sin documentos ni asignaciones | Reasignar antes de desactivar |
| Precio de lista / precio pactado | Dato | — | Siempre (con vigencia, no se borra: se cierra) | Se cierra con `valid_to` |
| Unidad de medida, afectación, ubigeo (catálogo SUNAT) | Catálogo oficial | No editable por el cliente | — | Los gestiona el proveedor |

#### Plataforma
| Entidad | Tipo | D cuando… | X solo si… | Notas |
|---|---|---|---|---|
| Cuenta (tenant) | Maestro | Solo proveedor; suspensión por falta de pago | Cierre con exportación | Ver §18.7 |
| Empresa | Maestro | Sin pendientes fiscales ni cupo en uso | Sin documentos | Solo proveedor |
| Sucursal | Maestro | Sin almacenes activos ni turnos abiertos | Sin documentos | |
| Almacén | Maestro | Stock en cero y sin reservas | Sin movimientos | |
| Usuario | Maestro | Siempre (cierra sesiones) | Sin acciones registradas | Conserva la autoría |
| Perfil (rol) | Maestro | Sin usuarios asignados | Ídem | Los de sistema no se eliminan |
| Serie de documentos | Maestro | Sin pendientes; no se reutiliza | **Nunca** | |
| Caja (registradora) | Maestro | Sin turno abierto | Sin turnos | |
| Cuenta bancaria / billetera | Maestro | Sin pagos pendientes de conciliar | Sin pagos | |
| Medio de pago | Maestro | Siempre | Sin pagos | |
| Plantilla de PDF / mensaje | Maestro | Debe quedar una predeterminada | Sin uso | Se versiona; no se borran versiones |
| Canal de notificación | Maestro | Siempre | Sin notificaciones enviadas | |
| Enlace del portal | Dato | **Revocar** (equivale a D) | No aplica | |

#### Documentos y operaciones
| Entidad | Borrador | Confirmado | Notas |
|---|---|---|---|
| Cotización | X | Estado `rejected` o `expired`; no se elimina | Se puede duplicar |
| Pedido | X (libera reservas) | **A** (cancelar) con motivo; libera reservas | Con anticipos: se decide devolver o dejar saldo a favor |
| Documento de venta | X | **A**; sigue flujo fiscal si es comprobante | Número no se reutiliza |
| Compra | X | **A** (genera contra-movimientos) | |
| Gasto | X | **A** | |
| Pago / cobro | — | **A** (revierte asignaciones y caja) | No se edita el monto: se anula y se registra otro |
| Envío | X | `incident`/`returned`; no se elimina | Los eventos son append-only |
| Documento de inventario (ajuste, traslado, toma) | X | **A** con contra-movimiento | |
| Turno de caja | — | Se cierra; no se elimina | Corrección: movimiento de ajuste con motivo |
| Lote de importación | — | **Revertir** el lote (borra lógicamente lo creado por él, si no tiene uso) | |

#### Libros y fiscal
| Entidad | Regla |
|---|---|
| Movimiento de stock, auditoría, eventos fiscales, eventos de envío, asignaciones de pago | Append-only: no se edita ni se elimina; se corrige con contra-asiento |
| Documento fiscal (`fisc.documents`) | Nunca se elimina; su estado cambia por el flujo fiscal |

#### Comportamientos transversales
- **Buscadores y selectores**: excluyen `inactive` y `deleted`. Los listados administrativos tienen el filtro "Mostrar inactivos".
- **Documentos antiguos**: siguen mostrando el nombre y datos del registro inactivo (con etiqueta "Inactivo").
- **Reportes**: incluyen inactivos; excluyen `deleted` sin uso (que por definición no tienen datos).
- **Importación**: si una fila coincide con un registro `inactive`, ofrece reactivarlo; con `deleted` crea uno nuevo.
- **API**: `DELETE` nunca borra físicamente; devuelve el resultado de la guardia (`deleted`, `deactivated` o `blocked` con motivos).

## 19. Cálculo de importes (alineado con eFact)

**Decisión:** el ERP replica el algoritmo de `DocumentCalculationService` de eFact (verificado en su código) para que ambos obtengan los mismos centavos. Los montos que devuelve eFact siguen siendo la fuente fiscal.

### 19.1 Reglas, por línea
| Concepto | Regla |
|---|---|
| Valor unitario (gravado 10) | `unit_value = round(unit_price / (1 + igv_rate), 10)` |
| Valor unitario (20, 30, 40) | `unit_value = unit_price` |
| Valor unitario (gratuitas 11–16, 21, 31–36) | `unit_value = 0`; el valor referencial se informa aparte |
| Descuento por línea | `base = round(unit_value × qty, 2)`; `factor = round(pct / 100, 6)`; `discount = round(base × factor, 2)` |
| Valor de venta | `sale_value = round(unit_value × qty − discount_base, 2)` |
| Total con IGV | `total_with_tax = round(unit_price × qty, 2) − discount_with_tax` |
| IGV de la línea (10) | `igv = round(total_with_tax − sale_value, 2)` (por **diferencia**, no por tasa) |
| ISC | `round(sale_value × isc_pct, 2)`; la base del IGV pasa a `sale_value + isc` y el IGV se recalcula |
| ICBPER | `round(factor_icbper × qty, 2)` |

### 19.2 Reglas, por documento
- Cada total (gravadas, exoneradas, inafectas, exportación, gratuitas) = **suma** de `sale_value` de sus líneas.
- `igv_total` = suma del IGV de las líneas.
- Descuento global (cod. 02): `gravadas -= descuento` y `igv_total = round(gravadas × tasa_efectiva, 2)`, con `tasa_efectiva = igv_total / gravadas` antes del descuento.
- `sub_total = valor_venta + impuestos`; `total = sub_total − anticipos − descuentos_no_base`, nunca negativo.

### 19.3 Cómo se implementa sin divergir
1. Un único componente `TaxCalculator` (interfaz + implementación `EfactCompatibleCalculator`) en el módulo fiscal; el resto del sistema lo usa por inyección.
2. Aritmética **decimal** (cadenas con `bcmath` o librería de decimales), nunca `float`. eFact usa `float`: por eso hay **pruebas de oro** con casos reales (gravado, descuentos, ISC, ICBPER, gratuitas, redondeos límite) que comparan el ERP contra eFact en entorno beta.
3. Se envía a eFact los **datos de entrada** (precio con IGV, cantidad, descuentos), no los totales; eFact recalcula y el ERP compara su resultado con lo devuelto. Diferencia distinta de cero → alerta y se conserva el valor de eFact.
4. Si eFact cambia su algoritmo, se actualiza el vector de pruebas y se agrega una nueva implementación (abierto/cerrado), no se edita código disperso.
5. Documentos no fiscales (nota de venta, pedido) usan el mismo calculador para que el total no cambie al convertirse en comprobante.

### 19.4 Casos límite a cubrir en pruebas
Precio con y sin IGV; descuento por línea en porcentaje y en monto; descuento global; ISC + IGV; ICBPER; línea gratuita; línea exonerada con IGV de empresa en 0; NRUS (afectación por defecto 30); cantidades fraccionarias; precios con 6 decimales; documentos con más de 50 líneas.
