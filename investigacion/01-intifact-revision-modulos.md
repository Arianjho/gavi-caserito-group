# Revisión de IntiFact (referencia) — módulo por módulo

Fecha: 2026-10-11. Fuente: cuenta de prueba vacía (rubro Electrónica, plan Básico, 1 sucursal). Se leyeron listados, KPIs y formularios; **no se guardó nada**.
No se vio: flujo de cobro del POS con ítems, emisión real de comprobantes, formulario de orden de compra (exige proveedor), "Nueva cotización" (dio error de la app), tienda online.

## Mapa de navegación

| Grupo | Pantallas |
|---|---|
| Inicio | Dashboard, IntiFact IA |
| Ventas | Nueva venta (POS), Entregas pendientes, Ventas y comprobantes, Pagos Yape |
| Facturación | Comprobantes, Pendientes de envío, Series, Guías de remisión, Transporte |
| Clientes | Clientes, Cuentas por cobrar |
| Productos e inventario | Productos (+categorías, marcas), Stock (Almacenes, Kardex, Movimientos, Traspasos, Ajustes), Compras (Órdenes, Cotizaciones de compra, Proveedores) |
| Finanzas y reportes | Gastos (+categorías, presupuestos), Cotizaciones, Reportes |
| Configuración | Empresa, Sucursales, Métodos de pago, Información de pago, API, Usuarios, Auditoría |

## Módulos

### Dashboard
Accesos rápidos (nueva venta, producto, pedidos, reportes). KPIs: ventas de hoy vs ayer, pedidos pendientes por cobrar, stock bajo, total productos. Gráficos: ventas 7 días, ventas por método de pago, ventas mensuales 6 meses. Listas: últimas ventas, cobros de hoy (con enlace a arqueo), stock bajo.

### POS (Nueva venta)
- Buscador por nombre/SKU/código, escáner de cámara, vista grilla/lista, filtros por categoría.
- Panel cliente por DNI/RUC (opcional), con alta rápida y botón IA.
- Carrito con resumen por afectación (ej. "Exonerado IGV 0%") y total; botón Cobrar (atajo F4); panel de atajos.
- Indicador de sucursal, usuario y hora; sonido y impresión.
- Opción de venta "Lo recoge después" (alimenta Entregas pendientes).
- Serie/IMEI obligatoria por producto y garantía en días (se guarda fecha de vencimiento).

### Entregas pendientes
Mercadería vendida no retirada. KPIs: esperando retiro, vencidas, entregadas. Tabs Pendientes/Entregadas. Columnas: venta, cliente, estado, por entregar, tiempo guardado.

### Ventas y comprobantes (historial POS)
KPIs: total vendido, ticket promedio, # ventas, productos vendidos. Filtros: Todas/Comprobantes/Tickets, período, tipo, estado, vendedor. Export CSV.

### Pagos Yape
Captura de yapeos desde la notificación del celular Android (app instalada en el móvil del negocio). Campos: hora, de, código de seguridad, celular, monto. Reenvío de alertas por rol (dueño, gerentes, vendedores, almaceneros, contadores). Conciliación de pagos digitales / anti-fraude.

### Comprobantes
Filtros: tipo, estado, período, vendedor. Columnas: tipo, serie-número, cliente, vendedor, fecha, total, estado.

### Pendientes de envío
Cola SUNAT. KPIs: total por facturar, pendientes, con errores, monto. Filtro por origen. Reintento de documentos con error.

### Series
Por tipo, serie, próximo correlativo, sucursal, estado. Por defecto: B001 boleta, F001 factura, BC01 y FC01 nota de crédito, TK01 ticket interno. Cotizaciones tienen su propia serie.

### Guías de remisión (electrónica)
- Importa comprobante ya emitido y precarga cliente, dirección, ítems.
- Tipo: Remitente (09) o Transportista (31). Modalidad: transporte privado o público.
- Destinatario: tipo doc, RUC, razón social.
- Origen y destino: ubigeo (depto/prov/dist) + dirección.
- Traslado: motivo (venta, etc.), fecha inicio, peso total, unidad, bultos.
- Vehículo y chofer: placa, DNI, licencia, nombres; opción M1/L sin placa ni chofer; indicadores SUNAT opcionales.
- Ítems: descripción/catálogo, cantidad, unidad. Observaciones.

### Transporte (flota)
Vehículos, choferes y empresas de transporte; se autoguardan al emitir una guía. KPIs y dados de baja. Exportar Excel.

### Clientes
Alta con tipo doc DNI (boleta), CE (extranjero, boleta), RUC (factura). Campos: nombre, apellido, ubigeo, teléfono, email. Se crean automáticamente al emitir. KPIs: total, email verificado, newsletter, nuevos del mes. Importar/exportar. Origen POS y e-commerce.

### Cuentas por cobrar
KPIs: total por cobrar, vencido, cuentas activas. Tabs Por cobrar/Pagadas. Columnas: cliente, total, pagado, debe, vence.

### Productos
- General: nombre, descripción (rich text), SKU y código de barras (auto), categoría, marca, unidad de medida (catálogo SUNAT), código de producto SUNAT (obligatorio desde 2027-01-01).
- Tributación: aplica IGV, tipo de afectación (10 gravado, etc.), tasa.
- Conversiones/presentaciones (saco, caja, docena) con stock siempre en unidad base.
- Series/IMEI, garantía, imágenes (principal, secundarias), complementarios (cross-sell).
- Flags: visible en tienda, destacado, complementario, controla stock, es servicio.
- Variantes (toggle).
- Precios y stock **por sucursal**: costo, venta, oferta, stock inicial, mínimo; precios por mayor por tramos.
- Extras: ordenar ofertas, códigos de barras/QR, importar/exportar Excel; estados activo/archivado.

### Stock
- **Almacenes:** valor de inventario al costo, stock bajo, movimientos del mes, por tienda.
- **Kardex:** buscar producto → registro valorizado.
- **Movimientos:** entradas, salidas, ajustes, traspasos (producto, tipo, cantidad, ubicación, usuario, fecha).
- **Traspasos:** estados pendiente / en tránsito / completado, ruta, solicitante.
- **Ajustes:** ubicación, tipo (conteo físico, merma, corrección), motivo, escaneo, notas.

### Compras
- **Proveedores:** RUC, razón social, nombre comercial, dirección, teléfono, email, contacto, **días de crédito**.
- **Órdenes de compra:** número, proveedor, destino, ítems, total, estado, fecha.
- **Cotizaciones de compra:** pedir precio a varios proveedores, comparar con flete y aduana, adjudicar y convertir en OC. Hay fecha límite de respuestas y notas.

### Gastos
Registro: categoría, monto, fecha, método de pago, descripción, **"tiene comprobante con RUC" (entra al Registro de Compras)**, recurrente. KPIs: total mes, pendientes de aprobación, % presupuesto, top categoría. Categorías con color y presupuesto/mes; presupuestos por categoría y mes.

### Cotizaciones (proformas)
Estados borrador, enviada, aceptada → convertir. Serie propia, validez, filtros por fecha. (La pantalla "nueva" falló en la prueba.)

### Reportes
Centro con asistente IA y reportes recomendados por rubro (Electrónica: capacidad×color, margen, sin rotación). Catálogo general: Ventas (análisis, más vendidos, comparativo, devoluciones/anulaciones); Inventario (stock, sin rotación, movimientos); Clientes (general, frecuentes); Finanzas (flujo de caja, arqueo de caja, margen, ingresos del mes, **detracciones SPOT**); Contabilidad (cierre: registro de ventas, compras e IGV); Restaurante (comandas, horas pico, rotación de mesas, rendimiento de mesero…).

### IntiFact IA
Chat sobre el negocio con preguntas sugeridas (ventas de hoy, reposición, mejores clientes, análisis del mes, comparativos). Aviso de posibles errores y botón "Reportar".

### Configuración
- **Empresa:** nombre comercial, RUC (con consulta), razón social, dirección fiscal, ubigeo, teléfono, email corporativo.
- **Sucursales:** nombre, tipo (tienda/almacén), ciudad, canales (POS, e-commerce), estado.
- **Métodos de pago:** de fábrica efectivo, tarjeta, Yape, Plin, transferencia; se pueden agregar otros.
- **Información de pago:** cuentas bancarias y términos que se imprimen al pie del PDF A4 de comprobantes y cotizaciones.
- **API (planes Pro/Empresa):** catálogo con stock y precios, pedidos que descuentan inventario y se facturan al aprobar.
- **Usuarios:** nombre, email, teléfono, contraseña, rol (super admin, gerente, vendedor, almacenero, contador), sucursal, **matriz de permisos por módulo** (ver, crear, editar, eliminar) con módulos: Dashboard, Ventas POS, Productos, Inventario, Clientes, Reportes, Facturación, Guías, Cotizaciones, Verificación de pagos, Gastos…
- **Auditoría:** fecha, usuario, acción (crear…), entidad, ID, IP; filtros por período/usuario/entidad/acción.

## Observaciones para nuestro diseño

- **No tiene:** contabilidad (libros, PCGE, asientos), planilla/RRHH, producción, CRM real, activos fijos, multi-moneda/multi-país visible, conciliación bancaria.
- **Detalle útil a copiar:** producto con presentaciones y stock en unidad base; precios por sucursal y por tramo; flag "gasto con comprobante → Registro de Compras"; guías con "importar comprobante"; cola de pendientes de envío con errores; auditoría con IP.
- **Planes:** gating por sucursales, almacenes, productos, usuarios y comprobantes/mes; API y tienda online en planes altos.
- **Rubros:** el producto se especializa por rubro (12): afecta reportes y vocabulario (ej. restaurante con comandas y mesas).
