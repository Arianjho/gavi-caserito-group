# Flujos y pantallas

Fecha: 2026-10-11. Estado: borrador. Base: `01-alcance-mvp.md`, `02-arquitectura.md`, `03-modelo-de-datos.md`, `05-sistema-visual.md`.
Convenciones de contenedor: **M** = modal, **P** = panel lateral, **Pg** = página completa, **POS** = pantalla propia. Los textos son referencias en español; en código van como claves de traducción.

## 1. Mapa de navegación

Menú lateral por **categorías con desplegables**. Inicio y Reportes son elementos directos.

```
Inicio
Comercialización       POS · Cotizaciones · Pedidos · Ventas · Devoluciones y notas
Despacho y envíos      Por armar · Envíos · Agencias
Cobranzas y pagos      Por cobrar · Cobros · Por pagar · Pagos
Compras                Compras · Gastos
Inventario             Stock · Kardex · Ajustes · Traslados · Toma física
Catálogo               Productos · Categorías · Marcas · Atributos · Listas de precios · Etiquetas
Terceros               Clientes · Proveedores
Caja                   Turnos · Movimientos
Facturación            Comprobantes · Pendientes y rechazados · Guías · Series
Reportes
Configuración          Empresa · Sucursales y almacenes · Usuarios y perfiles · Medios de pago
                       Plantillas y marca · Notificaciones · Importar · Ayuda
```

- El menú se construye con los permisos del usuario; un grupo sin ítems visibles no aparece.
- El grupo de la pantalla activa se abre solo; el resto permanece cerrado y se recuerda por usuario.
- Los módulos desactivados por plan o configuración (caja, compras, facturación) se muestran con candado o desaparecen.
- **Selector de empresa y sucursal** en la barra superior (si la cuenta tiene varias). Cambiarlo recarga datos y atajos.
- **Panel del proveedor** (super-admin) es una zona aparte con otro acceso.

## 2. Roles de referencia (plantillas)
| Perfil | Enfoque | Pantallas principales |
|---|---|---|
| Administrador | Todo en su empresa | Todas |
| Vendedor | Cotizar, pedir, consultar | Cotizaciones, Pedidos, Clientes, Stock (lectura) |
| Cajero | Cobrar y cerrar caja | POS, Caja, Cobros |
| Almacenero | Armar y despachar | Por armar, Envíos, Stock, Traslados |
| Contador | Revisar y exportar | Comprobantes, Compras, Reportes, Cobranzas |

## 3. Inventario de pantallas

### 3.1 Inicio
| Pantalla | Contenedor | Contenido |
|---|---|---|
| Panel de inicio | Pg | Tarjetas: ventas de hoy (monto y n.º), por cobrar (vencido y por vencer), por pagar, pedidos por estado, envíos pendientes, stock bajo, estado de caja, comprobantes con problema. Gráfico de ventas de 7/30 días. Accesos rápidos: Nueva venta, Nuevo pedido, Nuevo cliente. Filtro por sucursal y rango |

### 3.2 Vender
| Pantalla | Cont. | Contenido y acciones |
|---|---|---|
| **POS** | POS | Ver §5.1 |
| Cotizaciones (lista) | Pg | Filtros: estado, cliente, vendedor, fecha. Acciones: ver, duplicar, enviar por WhatsApp, convertir a pedido |
| Cotización (crear/editar) | Pg | Cliente, líneas, descuentos, vigencia, condiciones, notas |
| Pedidos (lista) | Pg | Pestañas por estado (Borrador, Confirmados, En despacho, Listos, Despachados, Entregados); chips de saldo y envío |
| Pedido (crear/editar) | Pg | Cliente y dirección, líneas con variante y empaque, reserva, anticipos, entrega (recojo/envío/reparto), notas |
| Pedido (detalle) | Pg | Cabecera, líneas con cantidades por etapa, línea de tiempo, pagos, envíos, comprobantes, acciones por estado |
| Ventas (lista) | Pg | Documentos por tipo (nota de venta, boleta, factura, NC, ND), estado de pago y fiscal |
| Venta (detalle) | P | Resumen, líneas, pagos, estado fiscal, descargas, reimprimir, compartir, anular |
| Venta rápida / nota de venta | Pg | Igual que POS pero con formulario completo y descuentos avanzados |
| Nota de crédito/débito | Pg | Documento afectado, motivo (cat. 09/10), líneas a revertir |
| Aplicar anticipo | M | Selecciona anticipos del cliente y monto a aplicar |

### 3.3 Despachos
| Pantalla | Cont. | Contenido |
|---|---|---|
| Por armar (tablero) | Pg | Columnas **Confirmados · En despacho · Listos**; tarjeta con cliente, líneas y faltantes. Acción "Iniciar armado" |
| Armado de pedido | Pg | Lista de picking con escaneo de código de barras, cantidades armadas, faltantes, bultos |
| Envíos (lista) | Pg | Filtros por estado, agencia, fecha; indicadores de cobro en destino |
| Envío (crear) | P | Agencia y modalidad, oficina de origen y destino (con referencia), entrega (agencia o domicilio), fecha de traslado, destinatario (nombre, DNI/RUC, teléfono), orden y código de la agencia, comprobante de la agencia (subir foto o PDF), descripción, bultos, peso real y cobrado, garantía, flete y forma de pago, cobro en destino, observaciones, GRR opcional |
| Envío (detalle) | P | Línea de tiempo, evento nuevo, foto del cargo, enlace de seguimiento, reimprimir rótulo |
| Registrar evento | M | Estado, nota, foto |
| Rótulos | P | Vista previa por bulto, formato (100×150, A6, A4 · 4 por hoja), imprimir uno o todos |
| Agencias | Pg + M | Lista; alta/edición en modal; oficinas de destino en pestaña |

### 3.4 Cobranzas, pagos y compras
| Pantalla | Cont. | Contenido |
|---|---|---|
| Por cobrar | Pg | Agrupado por cliente con antigüedad (por vencer, 0–30, 31–60, 61–90, +90); acciones: cobrar, enviar recordatorio, ver estado de cuenta |
| Registrar cobro | M | Cliente, documentos pendientes (selección múltiple), medios de pago (mixto), referencia, saldo a favor |
| Estado de cuenta | Pg | Movimientos, saldo, exportación PDF y envío por WhatsApp |
| Por pagar | Pg | Igual que Por cobrar para proveedores |
| Registrar pago | M | Proveedor, documentos, medio, cuenta |
| Compras (lista y alta) | Pg | Proveedor, documento, líneas con variante, condición contado/crédito, cuotas, almacén destino |
| Gastos | Pg + M | Lista; alta en modal con categoría, monto, comprobante adjunto |

### 3.5 Inventario y catálogo
| Pantalla | Cont. | Contenido |
|---|---|---|
| Stock | Pg | Por variante y almacén: físico, reservado, disponible, costo, valor; filtros; exportar |
| Kardex | Pg | Movimientos por variante, almacén y rango; origen enlazado |
| Ajuste de inventario | Pg | Líneas, motivo, costo; requiere permiso |
| Traslado | Pg | Origen, destino, líneas; estado enviado/recibido |
| Toma física | Pg | Conteo por escaneo; diferencia; confirmar ajuste |
| Alertas de stock | Pg + M | Mínimos y reposición; regla por almacén |
| Productos (lista) | Pg | Búsqueda por nombre, código, código de barras; filtros; vista tabla/tarjetas |
| Producto (crear/editar) | Pg | Pestañas: General (tipo bien/servicio, UM por defecto NIU/ZZ, afectación), Variantes y atributos, Empaques y precios, Códigos de barras, Stock por almacén, Imágenes |
| Producto (detalle rápido) | P | Resumen, stock, precios, últimos movimientos |
| Categorías, marcas, atributos | Pg + M | Lista con alta/edición en modal |
| Listas de precios | Pg | Lista y edición con escalas por cantidad |
| Etiquetas | Pg | Selección de variantes, formato, cantidad, vista previa, imprimir |

### 3.6 Terceros
| Pantalla | Cont. | Contenido |
|---|---|---|
| Clientes / Proveedores (lista) | Pg | Búsqueda por nombre y documento; saldo; estado |
| Tercero (crear) | M | Tipo de documento, número (con búsqueda RUC/DNI), nombre, teléfono, correo |
| Tercero (detalle) | P → Pg | Datos, direcciones, contactos, condiciones por empresa, pedidos, ventas, pagos, saldo, consentimientos |
| Fusionar terceros | M | Selección del registro a conservar y vista previa de lo que se moverá |
| Eliminar/desactivar | M | Resultado de la guardia (§6.15) |

### 3.7 Caja
| Pantalla | Cont. | Contenido |
|---|---|---|
| Abrir turno | M | Caja, monto inicial o conteo por denominación |
| Turno actual | Pg | Resumen en vivo: ventas por medio, ingresos, egresos, esperado |
| Movimiento de caja | M | Ingreso/egreso, motivo, monto |
| Cerrar turno | M | Conteo por denominación PEN o monto final; diferencia; observación |
| Historial de turnos | Pg | Por caja, cajero y fecha; detalle y reimpresión |

### 3.8 Facturación electrónica
| Pantalla | Cont. | Contenido |
|---|---|---|
| Comprobantes | Pg | Estado SUNAT por documento; descargas XML/CDR/PDF; reintento manual |
| Pendientes y rechazados | Pg | Cola de acción: motivo, corrección, reenvío |
| Guías de remisión | Pg | Lista y emisión desde un envío |
| Series | Pg | Series por sucursal; estado de sincronización con eFact |
| Configuración fiscal | Pg | Activación guiada (ver §6.12), estado de conexión, cupo del mes, entorno |
| Anulación | M | Método sugerido (resumen, baja o nota de crédito), motivo, confirmación |

### 3.9 Configuración
| Pantalla | Cont. | Contenido |
|---|---|---|
| Empresas | Pg | Lista; datos, régimen, modo fiscal, logo y color |
| Sucursales y almacenes | Pg + M | Alta y edición en modal; estado |
| Usuarios | Pg + M | Alta/edición con perfil y alcance; invitación por WhatsApp o correo |
| Perfiles y permisos | Pg | Matriz módulo × acción, límites y aprobaciones |
| Medios de pago y cuentas | Pg + M | Activación por empresa |
| Plantillas y marca | Pg | Logo, color, plantillas por documento con vista previa |
| Notificaciones | Pg | Eventos, destinatarios, plantillas y horarios |
| Importar | Pg | Asistente (ver §6.11) |
| Ayuda y tutoriales | Pg | Recorridos guiados, videos cortos, plantillas descargables |

### 3.10 Portal del cliente (público, por enlace)
| Pantalla | Contenido |
|---|---|
| Mis pedidos | Lista con estado y fecha |
| Detalle del pedido | Línea de tiempo, productos, comprobantes, envío (agencia, guía, clave), saldo |
| Mi cuenta | Saldo, documentos pendientes, instrucciones de pago |
| Pedir de nuevo | Envía una **solicitud** a su vendedor con las líneas del pedido anterior; el cliente no crea pedidos |
| Seguimiento público | Estado de un pedido por enlace, sin datos sensibles |

### 3.11 Panel del proveedor
Cuentas, plan, vencimiento, registro manual de cobros, suspensión, uso de cupo de emisión, salud de integraciones (eFact, Evolution), notificaciones de sistema, catálogos SUNAT.

## 4. Estados y transiciones visibles al usuario
| Entidad | Transiciones principales | Quién | Notifica |
|---|---|---|---|
| Pedido | borrador → confirmado | vendedor | cliente (confirmación) |
| Pedido | confirmado → en despacho → listo | almacenero | — |
| Pedido | listo → despachado | almacenero | cliente (con guía y clave) |
| Pedido | despachado → entregado | almacenero o automático | cliente, dueño |
| Pedido | cualquiera → cancelado | con permiso y motivo | cliente |
| Envío | por despachar → despachado → en tránsito → en agencia destino → entregado | almacenero | cliente |
| Venta | borrador → confirmada → anulada | cajero / admin | — |
| Comprobante | por emitir → aceptado / rechazado | sistema | dueño si es rechazo |
| Cobro | pendiente → parcial → pagado → vencido | sistema | cliente (recordatorio de cuota) |

## 5. Pantallas críticas en detalle

### 5.1 POS
```
┌──────────────────────────────────────────────────────────────────────────────┐
│ ☰  Sucursal Centro · Caja 1 · Turno abierto 08:02 · Juan P.   ● En línea  ⋯ │
├───────────────────────────────────────────┬──────────────────────────────────┤
│ [ 🔍 Buscar o escanear código…          ] │  Cliente: Varios ▾  [+]          │
│ Categorías: Todos | Polos | Jeans | …     │ ──────────────────────────────── │
│ ┌────────┐ ┌────────┐ ┌────────┐ ┌─────┐ │  Polo M negro   2 × 25.00  50.00 │
│ │ Polo   │ │ Jean   │ │ Casaca │ │ …   │ │  Jean 32 azul   1 × 80.00  80.00 │
│ │ S/25   │ │ S/80   │ │ S/120  │ │     │ │  ...                             │
│ └────────┘ └────────┘ └────────┘ └─────┘ │ ──────────────────────────────── │
│ Variantes: aparecen al elegir un producto  │  Subtotal           130.00      │
│                                            │  Descuento    [ - ]   0.00      │
│                                            │  TOTAL           S/ 130.00      │
│                                            │  Tipo: ○ Nota ○ Boleta ○ Factura │
│ [En espera (2)]  [Cliente]  [Descuento]    │  [   COBRAR  F8   ]             │
└───────────────────────────────────────────┴──────────────────────────────────┘
```
- Escaneo: el campo de búsqueda recibe el código; coincidencia exacta agrega la línea; múltiples coincidencias abren selector.
- Línea: cantidad con teclado, empaque (unidad/docena), descuento con permiso, precio según lista del cliente.
- **Ventas en espera:** guardar y recuperar.
- Tipo de documento según modo fiscal de la empresa (`none`: solo nota; `optional`: elige; `required`: boleta/factura).
- **Cobrar** abre el panel de pago: medios múltiples, monto recibido y vuelto, referencia (Yape/Plin/tarjeta), crédito con cuotas y fecha.
- Atajos: F2 buscar, F4 cliente, F8 cobrar, F9 en espera, Esc cierra paneles (no mientras procesa).
- Impresión automática del ticket; opción de enviar comprobante por WhatsApp.

### 5.2 Panel de pago (POS y ventas)
1. Total y saldo.
2. Condición: Contado o Crédito (cuotas).
3. Medios: agregar filas (medio, monto, referencia). El saldo baja.
4. Efectivo: monto recibido y vuelto.
5. Confirmar: bloquea, registra venta, pagos, movimiento de stock, caja y, si corresponde, encola la emisión.
6. Resultado: éxito con acciones (imprimir, WhatsApp, nueva venta).

## 6. Flujos

### 6.1 Alta del negocio y primera venta (onboarding)
1. Registro: nombre, correo, teléfono, contraseña (alta manual por el proveedor en v1 o autoalta con trial).
2. Asistente: rubro → datos de la empresa (RUC con autocompletado) → régimen tributario y si es afecta a IGV → sucursal y almacén → ¿emite comprobantes? (sí/no) → ¿usa caja con apertura? → ¿controla stock?
3. Carga de datos: crear productos manualmente, importar Excel o usar datos demo del rubro.
4. Recorrido guiado: "Registra tu primera venta".
5. Panel de inicio con lista de pasos pendientes (checklist).

### 6.2 Cotización → pedido → venta → envío → cobro
```
Cotización ─aceptar─▶ Pedido borrador ─confirmar─▶ Pedido confirmado (reserva stock)
   │                                                        │
   │                                         anticipo (opcional, cobro)
   │                                                        ▼
   │                                              En armado → Listo
   │                                                        │ facturar (opcional)
   │                                                        ▼
   │                                                Venta / comprobante
   │                                                        │
   │                                                despachar → Envío
   │                                                        ▼
   └──────────────────────────────────────────────  Entregado → cobro del saldo
```
Reglas:
- Convertir cotización a pedido copia líneas y precios vigentes; avisa si cambiaron.
- Confirmar reserva stock; si falta, muestra qué línea y permite reservar parcial o confirmar con faltante (según política).
- Anticipo: **Registrar anticipo** abre el modal de cobro ligado al pedido; queda como saldo a favor del pedido.
- Se puede facturar antes o después del despacho (configurable).
- Despacho parcial: se elige qué líneas y cantidades; el pedido pasa a "parcialmente despachado".

### 6.3 Venta de mostrador (contado, mixto o crédito)
1. Agregar productos (escaneo o búsqueda).
2. Seleccionar cliente (opcional; obligatorio para factura y crédito).
3. Cobrar: medio(s) o crédito con cuotas.
4. Confirmar → se registran documento, stock, pagos, caja; si hay emisión, entra a la cola fiscal.
5. Se muestra el resultado; el estado fiscal llega después por aviso.

### 6.4 Cobro de deudas
1. Por cobrar → cliente → seleccionar documentos o cuotas.
2. Registrar cobro: medios, referencia, monto.
3. Si el monto excede, el excedente queda como saldo a favor.
4. Se emite recibo (PDF) y se puede enviar por WhatsApp.
5. Si es en efectivo y la caja usa turnos, queda en el turno actual.

### 6.5 Compra al contado o crédito
1. Compras → nueva: proveedor, documento (tipo, serie, número), fecha, almacén.
2. Líneas con variante, cantidad, costo, afectación.
3. Condición: contado (registra pago) o crédito (cuotas y vencimientos).
4. Confirmar: ingresa stock con costo, genera cuenta por pagar si es crédito.
5. Pagos posteriores desde Por pagar.

### 6.6 Armado y despacho
1. El almacenero abre **Por armar**, toma un pedido (se asigna).
2. Armado: escanea o marca cantidades; registra faltantes y bultos.
3. Marca **Listo**; se puede imprimir lista de empaque y rótulos.
4. **Crear envío**: agencia, oficina destino, guía de agencia y clave (cuando la tiene), flete y quién lo paga, cobro en destino.
5. Si corresponde, emitir guía de remisión (paso opcional).
6. Se notifica al cliente con guía, clave y enlace de seguimiento.
7. Actualiza estados manualmente; al marcar entregado puede adjuntar foto del cargo.

### 6.7 Cobro en destino (contra entrega)
1. El envío registra `cod_amount`.
2. Al entregar, el almacenero o el transportista propio registra el monto cobrado y la diferencia.
3. El cobro entra como pago ligado al envío y al pedido; si hay diferencia, queda saldo.
4. La rendición del repartidor se cuadra en un turno o movimiento de caja.

### 6.8 Caja: apertura, arqueo y cierre
- **Con turnos:** Abrir turno (monto inicial o conteo) → operar → Cerrar (conteo por denominación PEN o monto final) → diferencia con observación → reporte.
- **Sin turnos:** los movimientos de caja quedan por día y caja; el arqueo es opcional y manual.
- Una caja con turno abierto no puede desactivarse.

### 6.9 Anulación de venta
1. Venta → Anular → motivo.
2. El sistema evalúa: nota de venta (anulación local) o comprobante (resumen, baja o NC según estado y plazo).
3. Si hay pagos: elegir devolver o dejar saldo a favor; si hay stock: se revierte.
4. Requiere permiso o aprobación de supervisor por límite.
5. Confirmación con bloqueo del diálogo; resultado con línea de tiempo.

### 6.10 Emisión y rechazo fiscal
1. Venta confirmada con comprobante → `por emitir` → cola.
2. Estado visible en la venta (chip) y en Comprobantes.
3. **Aceptado:** se guardan XML, CDR y PDF; se habilita compartir.
4. **Rechazado:** aparece en Pendientes y rechazados con el motivo en lenguaje claro y acción sugerida (corregir cliente, serie, afectación…).
5. Corregir y reenviar; o convertir a nota de venta si el negocio decide no emitir.
6. Si eFact o SUNAT no responde: la venta no se bloquea; el comprobante queda pendiente con reintentos y aviso.

### 6.11 Importación desde Excel
1. Importar → elegir entidad (productos con variantes, clientes, proveedores, stock inicial, por cobrar, por pagar).
2. Descargar plantilla (con ejemplos y hoja de instrucciones) o subir un Excel propio.
3. Mapear columnas (el sistema propone coincidencias).
4. **Validación en seco:** informe con filas correctas, advertencias y errores por fila; descarga del informe.
5. Confirmar: procesamiento en segundo plano con barra de avance.
6. Resumen y opción de **revertir el lote**.
7. Para datos demo: lote marcado "demo" con botón "Quitar datos de ejemplo".

### 6.12 Activación de la facturación electrónica
1. Configuración fiscal → "Activar emisión".
2. Requisitos guiados: RUC y razón social, régimen, certificado digital (.pfx), usuario y clave SOL secundarios, entorno beta para pruebas.
3. El sistema aprovisiona la empresa en eFact, crea sucursales y series y guarda las credenciales cifradas.
4. **Prueba en beta:** emite un comprobante de prueba y verifica la respuesta.
5. Paso a producción cuando el negocio confirme; cambio de entorno y de series.
6. Indicadores: cupo del mes, estado de conexión, último envío aceptado.

### 6.13 Notificaciones (ejemplos)
| Evento | A quién | Mensaje (referencia) |
|---|---|---|
| Pedido confirmado | Cliente | "Hola {cliente}, recibimos tu pedido {pedido}. Total {total}. Te avisaremos cuando salga." |
| Pedido despachado | Cliente | "Tu pedido {pedido} salió por {agencia}. Guía: {guia}. Clave de recojo: {clave}. Sigue el estado: {enlace}" |
| Cuota por vencer | Cliente | "Tu cuota de S/ {monto} vence el {fecha}." |
| Comprobante rechazado | Dueño | "El comprobante {numero} fue rechazado: {motivo}." |
| Cierre de caja | Dueño | "Cierre de {caja}: ventas {ventas}, diferencia {dif}." |
| Cupo de emisión agotándose | Dueño y proveedor | "Quedan {n} comprobantes del mes." |

### 6.14 Portal del cliente
1. El negocio comparte el enlace por WhatsApp (desde el pedido o el cliente).
2. El cliente abre sin contraseña; ve sus pedidos y estados.
3. Puede ver la guía y la clave, descargar comprobantes y consultar su saldo.
4. **Pedir de nuevo** envía una solicitud al vendedor; el vendedor crea y confirma el pedido.
5. El enlace caduca y puede revocarse.

### 6.15 Eliminar, desactivar y fusionar
1. En cualquier maestro: acción **Eliminar**.
2. Guardia de referencias:
   - **Sin uso:** confirmación y eliminación lógica.
   - **Con uso:** diálogo con el recuento ("14 pedidos, 3 ventas, S/ 420.00 por cobrar") y dos salidas: **Desactivar** y, si aplica, **Fusionar**.
3. Desactivar: efecto inmediato, reversible desde "Mostrar inactivos".
4. Fusionar: elegir destino, ver vista previa, confirmar; proceso con bloqueo y resultado.
5. Documentos: borrador → eliminar; confirmado → anular.

### 6.16 Aprobación de supervisor
1. Una acción supera el límite del perfil (descuento, anulación, ajuste).
2. El sistema ofrece **Solicitar aprobación** (PIN del supervisor en el mismo equipo, o solicitud a su celular).
3. El supervisor aprueba con motivo; queda en auditoría.
4. La acción continúa sin perder el contexto del formulario.

### 6.17 Toma de inventario físico
1. Crear toma: almacén y alcance (todo, categoría, ubicación).
2. Contar por escaneo o tabla; se congela el stock teórico.
3. Revisar diferencias.
4. Confirmar: genera ajuste con motivo; desbloquea.

## 7. Estados vacíos, errores y casos especiales
- **Primera vez:** cada lista vacía explica qué es y ofrece la acción principal más un enlace al tutorial.
- **Sin permiso:** la acción no aparece; si se llega por enlace, pantalla "No tienes acceso" con a quién pedirlo.
- **Sin conexión:** indicador permanente; guardado local solo donde el nivel 1 lo permite (D19).
- **Plan sin función:** candado con invitación a contactar al proveedor.
- **Cuota de emisión agotada:** la venta continúa como nota de venta o queda pendiente de emisión según la configuración.
- **Concurrencia:** si otro usuario modificó el registro, mensaje de conflicto con opción de recargar y reaplicar.

## 8. Reglas de interfaz por tipo de pantalla
| Tipo | Regla |
|---|---|
| Listas | Búsqueda arriba, filtros visibles en chips, orden por columna, paginación por cursor, acciones de fila en menú, exportar |
| Formularios en modal | ≤ 8 campos; botón principal con verbo; bloqueo y toast según `05-sistema-visual.md` |
| Detalle | Panel lateral con pestañas y enlace "Abrir completo" |
| Documentos | Cabecera fija con totales visibles; guardado como borrador sin perder datos |
| Tablas de líneas | Edición en línea, teclado, agregar por escaneo |
| Estados | Chips de color consistentes (borrador gris, confirmado azul, éxito verde, advertencia ámbar, error rojo) |

## 9. Pendientes de este documento
1. Wireframes de baja fidelidad en `wireframes.html` (POS, pedido, producto, envío, caja, portal).
2. Definir textos finales y diccionario de errores por flujo.
3. Matriz de permisos exacta (`modulo.recurso.accion`) por pantalla.
4. Mensajes de WhatsApp aprobados y versión en correo.
5. Revisar con comerciantes reales cuando haya acceso.

## 10. Rótulo de envío (definido)
Un rótulo por **bulto**, impreso desde el envío. Contenido:

| Bloque | Datos |
|---|---|
| Remitente | Logo, nombre comercial y teléfono |
| Destino | Ciudad y modalidad (terrestre/aéreo), agencia, oficina y referencia |
| Destinatario | Nombre, **DNI/RUC** y teléfono |
| Bulto | "n / N" |
| Agencia | **Orden y código** de la agencia, número de pedido y de envío |
| Cobro | **Cobro en destino** resaltado y estado del flete |
| Seguimiento | QR al enlace público del pedido |
| Observaciones | Frágil, no apilar u otra indicación |

Formatos: 100 × 150 mm (térmica), A6 y A4 con 4 por hoja; se elige por sucursal.
El comprobante de la agencia (foto o PDF) se sube al envío y se muestra en el seguimiento.

## 11. Impresión por sucursal
Cada sucursal elige el formato por **tipo de documento**: ticket 58 mm, ticket 80 mm, A5 o A4 (y, para rótulos y etiquetas, los formatos propios). Se configura en *Configuración → Sucursales y almacenes → Impresión*. Siempre se puede reimprimir un documento en otro formato.
- Comprobantes electrónicos: PDF de eFact en el formato elegido.
- Resto de documentos: plantilla de la empresa renderizada por el sistema.
