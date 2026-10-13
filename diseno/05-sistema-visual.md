# Sistema visual y contrato de interacción

Fecha: 2026-10-11. Estado: borrador. Kit base: `aura-admin-theme` (ver D6 en `00-decisiones-base.md`).

> Los estándares de implementación (tokens, componentes y proceso) están en `../construccion/02-estandares-frontend.md`.

## 1. Principios
1. **Compacto y corporativo:** densidad media, radio ~0.5rem, tipografía legible, poca decoración.
2. **El usuario nunca pierde el contexto:** nada cambia ni se cierra mientras un proceso se ejecuta.
3. **El error se explica:** qué pasó y qué hacer, sin códigos técnicos.
4. **Marca del cliente:** el panel es marca blanca (ver §5).
5. **Mobile first** en POS, pedidos y consulta.

## 2. Contrato de carga y bloqueo

### Carga inicial de datos
| Situación | Patrón |
|---|---|
| Lista o tabla | **Skeleton** con la forma de la fila; conservar datos previos al refrescar (sin saltos) |
| Detalle o formulario de edición | Skeleton del formulario; no mostrar campos vacíos editables |
| Tarjetas del panel | Skeleton por tarjeta, carga independiente |
| Búsqueda en vivo | Indicador discreto en el campo; resultados previos visibles |
| Error de carga | Estado de error en el bloque con **Reintentar**, sin vaciar la pantalla |

### Envío (submit) y procesos que llaman a un endpoint
Mientras la petición está en curso, el componente aplica **bloqueo de contexto**:

1. Todos los campos del formulario quedan **deshabilitados** (`fieldset disabled`), no solo el botón.
2. El botón principal pasa a estado de proceso con texto del verbo: **"Registrando…"**, "Guardando…", "Anulando…", "Emitiendo…"; con indicador y sin permitir un segundo clic.
3. El diálogo **no se puede cerrar**: botón X, tecla Esc y clic fuera quedan inhabilitados; el botón Cancelar también.
4. Navegación: aviso al salir de la pestaña mientras hay un proceso en curso.
5. Al terminar:
   - **Éxito:** se cierra el diálogo, aparece el toast y se actualiza la lista.
   - **Error corregible (422, regla de negocio):** el diálogo **permanece abierto**, se reactivan los campos, se marcan los campos con error y aparece un toast de advertencia.
   - **Error del sistema:** el diálogo permanece abierto, datos intactos, toast de peligro con **Reintentar**.

### Reglas para que el bloqueo no atrape al usuario
- **Tiempo máximo:** a los 15 s se muestra "Sigue procesando…"; a los 30 s la petición se corta y se informa **"No pudimos confirmar si se guardó"**. El usuario puede cerrar, y el sistema consulta el resultado con la misma `Idempotency-Key` antes de permitir reintentar (evita duplicados).
- **Reintento seguro:** cada intento de guardar reutiliza su `Idempotency-Key`; una llave nueva solo se genera tras un resultado definitivo.
- **Sin optimismo ciego:** solo se aplican actualizaciones optimistas a acciones reversibles y sin efecto fiscal o de stock (por ejemplo, marcar favorito). Registrar ventas, cobros, anulaciones y emisiones esperan al servidor.
- **Acciones de fila** (archivar, anular, avanzar estado): la fila muestra su propio estado de proceso y se bloquean sus acciones; el resto de la tabla sigue usable.
- **Confirmaciones destructivas:** diálogo de confirmación con el nombre del elemento; mismo bloqueo mientras se ejecuta.
- **POS:** el cobro no usa diálogos modales encadenados; el panel de pago se bloquea igual, pero con tiempo máximo corto y camino claro para reintentar sin duplicar la venta.

### Componentes a construir (kit propio sobre el tema)
`<FormDialog>`, `<ConfirmDialog>`, `<ActionButton>` (con estados), `useGuardedMutation` (aplica bloqueo, llave de idempotencia, tiempo máximo y mapeo de errores), `<DataTable>` con skeleton y acciones de fila, `<EmptyState>`, `<ErrorState>`.

## 3. Notificaciones (toast)
| Tipo | Cuándo | Aspecto | Duración |
|---|---|---|---|
| **Éxito** (verde) | La acción terminó bien | Una línea + acción opcional ("Ver pedido") | 4 s |
| **Advertencia** (ámbar) | El usuario puede corregirlo: dato faltante, stock insuficiente, límite superado, permiso | Qué falló + qué hacer | 8 s, o hasta cerrar |
| **Peligro** (rojo) | Falla del sistema o de un servicio externo: red, servidor, SUNAT, WhatsApp | Qué pasó + **Reintentar** + código de soporte copiable | Hasta cerrar |
| **Información** (azul) | Estado neutral (procesando en segundo plano, sincronización) | Breve | 4 s |

Reglas de redacción:
- Frase corta, voz activa y en el idioma del negocio: *"No hay stock suficiente de Polo M negro (quedan 3)."*, no *"Error 422"*.
- Siempre decir **el siguiente paso**: *"Revisa el RUC e inténtalo de nuevo"*.
- Los mensajes salen de un **diccionario de errores** (`codigo` de la API → texto amigable); el servidor devuelve `codigo`, `mensaje` y `campos`.
- En peligro se muestra el `request_id` para soporte, no el detalle técnico.
- Accesibles (`aria-live`), apilables, sin tapar los botones principales del POS.

## 4. Reglas de pantallas
| Tipo | Contenedor |
|---|---|
| CRUD simple (≤ 8 campos) | Modal |
| Formulario medio | Panel lateral |
| Documentos (pedido, venta, compra, cotización) | Página completa |
| POS | Pantalla propia, sin menú de administración |
| Detalle de registro | Panel lateral con pestañas; opción de abrir en página |
| Nunca | Modal sobre modal |

"Eliminar" = archivar si hay movimientos.

## 5. Marca blanca por empresa
### Qué se personaliza
| Elemento | Dónde se ve |
|---|---|
| Logo | Barra del panel, portal del cliente, PDFs, tickets, correos y mensajes |
| Color principal (opcional) | Botones y acentos del panel y del portal |
| Nombre comercial y datos de contacto | PDFs, portal, pie de mensajes |
| Textos legales (condiciones, cuentas bancarias, pie) | PDFs |

El producto no muestra su propia marca dentro del panel del cliente. Un pie "Con tecnología de …" queda como opción del plan.

### Logo: tamaños recomendados
| Uso | Medida | Formato |
|---|---|---|
| Principal (barra, PDF A4) | 400 × 120 px, fondo transparente | PNG, SVG o WebP |
| Cuadrado (icono, favicon, WhatsApp) | 512 × 512 px | PNG |
| Ticket térmico | 384 px de ancho (58 mm) / 576 px (80 mm), monocromo | Generado automáticamente |
| Peso máximo | 2 MB | |

El sistema valida proporción y peso, genera las variantes (miniatura, cuadrado, versión monocroma para tickets) y muestra vista previa. Con tema oscuro y fondo claro conviene una variante: se permite subir logo claro y oscuro.

### Plantillas de PDF por documento
| Documento | Origen del PDF |
|---|---|
| **Comprobantes electrónicos** (boleta, factura, NC, ND) | **eFact**: representación impresa válida; el logo se sincroniza con `POST /empresa/logo` |
| Guía de remisión | eFact |
| **Cotización, pedido, reserva, nota de venta, estado de cuenta, orden de armado, rótulo de envío, ticket interno, recibo de cobro** | **El ERP**, con plantilla propia |
| Etiquetas de código de barras | El ERP |

Cada tipo de documento tiene su plantilla por empresa. La plantilla **no es HTML libre**: es un conjunto de bloques configurables.
- Formato: A4, A5, ticket 80 mm y ticket 58 mm. **El formato se elige por sucursal y por tipo de documento** (`branch_print_settings`); la plantilla define cómo se ve en cada formato.
- Cabecera: posición del logo, datos de la empresa, color.
- Cuerpo: columnas visibles (código, descripción, variante, cantidad, precio, descuento), totales, condiciones, cuentas bancarias.
- Pie: texto legal, agradecimiento, QR de seguimiento del portal.
- Versión previa con datos de ejemplo; plantilla predeterminada por tipo y formato; historial de cambios.

Un documento ya emitido conserva la plantilla vigente en su momento (se guarda la versión) para reimprimir igual.

## 6. Sin conexión
Resumen de decisión en D19; análisis completo en `../investigacion/05-factibilidad-sin-conexion.md`. Impacto visual:
- Indicador permanente de conexión (en línea / sin conexión / sincronizando).
- Con conexión caída, el POS cambia el verbo del botón a **"Guardar en este equipo"** y el toast informa que quedó pendiente de sincronizar.
- Lista de "Pendientes de sincronizar" con reintento y estado por venta.

## 7. Accesibilidad y rendimiento
- Navegación por teclado completa en POS (atajos documentados), contraste AA, foco visible.
- Carga perezosa por ruta; el POS es un paquete aparte y pequeño.
- Tablas virtualizadas desde 200 filas.

## 8. Rótulos y etiquetas
Formatos: 100 × 150 mm, A6, A4 con 4 por hoja (rótulos) y 40 × 25 mm o A4 con 24 por hoja (etiquetas de producto). Los rótulos incluyen DNI del destinatario y cobro en destino resaltado. Detalle en `04-flujos-y-pantallas.md` §10.
